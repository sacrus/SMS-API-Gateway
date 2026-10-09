// SMSAPI Gateway — TRADUCAO AUTOMATICA
//
// Mesmo mecanismo do site LP Logic: o servico de traducao do Google, com
// deteccao automatica do idioma do navegador.
//
// Ponto que custou uma iteracao: o contentor #google_translate_element NAO
// pode ficar com display:none. Escondido, o Google nao inicializa o widget
// dentro dele, o <select> nunca aparece e o selector nao existe. Por isso o
// contentor fica visivel e e o proprio widget do Google que se ve — apenas
// estilizado para combinar com o painel escuro.
//
// Diferencas em relacao ao LP Logic, deliberadas:
//   - A traducao e conduzida pelo proprio <select> do Google, o que preserva
//     o DOM. Substituir innerHTML destruiria os listeners do painel (CSRF,
//     separadores, accoes) e os valores dos formularios.
//   - A escolha explicita do utilizador gana sempre a deteccao automatica,
//     e o que e detetado nao e gravado — senao "Automatico" deixaria de
//     seguir o navegador.
//   - Sem MutationObserver: custava uma verificacao em cada alteracao de
//     estilo ou classe da pagina, sem necessidade.
//
// Se o script do Google nao carregar (bloqueio, firewall, offline), o
// selector desaparece e a pagina fica intacta em portugues.

(function () {
    'use strict';

    var STORAGE_KEY = 'smsapi_lang';
    var SLOT_ID = 'smsapi-translate';

    var pageLang = (document.documentElement.getAttribute('lang') || 'pt').substring(0, 2).toLowerCase();

    /** O seletor de idiomas que o utilizador pode escolher. */
    var LANGUAGES = [
        { code: 'auto', label: 'Automático' },
        { code: 'pt', label: 'Português' },
        { code: 'es', label: 'Español' },
        { code: 'en', label: 'English' },
        { code: 'fr', label: 'Français' },
        { code: 'de', label: 'Deutsch' },
        { code: 'it', label: 'Italiano' }
    ];

    function slot() {
        return document.getElementById(SLOT_ID);
    }

    function show() {
        var node = slot();
        if (node) { node.style.display = ''; }
    }

    function hide() {
        var node = slot();
        if (node) { node.style.display = 'none'; }
    }

    function stored() {
        try {
            var value = window.localStorage.getItem(STORAGE_KEY);
            return (value && isSupported(value)) ? value : null;
        } catch (e) {
            return null;
        }
    }

    function remember(code) {
        try { window.localStorage.setItem(STORAGE_KEY, code); } catch (e) { /* modo privado */ }
    }

    function isSupported(code) {
        for (var i = 0; i < LANGUAGES.length; i++) {
            if (LANGUAGES[i].code === code) { return true; }
        }
        return false;
    }

    /**
     * Idioma do navegador, se for suportado e diferente do original.
     *
     * Só se olha para navigator.language. As entradas seguintes de
     * navigator.languages sao preferencias de recurso para quando a pagina nao
     * existe noutro idioma: um navegador pt-PT reporta
     * ["pt-PT", "pt", "en-US", "en"] e, se as percorressemos, um portugues
     * veria o painel em ingles.
     */
    function browserLanguage() {
        var code = String(navigator.language || '').substring(0, 2).toLowerCase();
        if (code && code !== pageLang && isSupported(code)) { return code; }
        return null;
    }

    function container() {
        return document.getElementById('google_translate_element');
    }

    /** O <select> que o Google cria dentro do contentor. */
    function widgetSelect() {
        return document.querySelector('#google_translate_element select.goog-te-combo');
    }

/**
     * Marca o div que segura o <select>.
     *
     * O Google cria esse div sem classe nem id, dentro do .goog-te-gadget. Nao
     * da para o escolher por selector de CSS, e esconde-lo leva o selector
     * consigo — que e o que acontecia antes. Marca-se aqui para o CSS poder
     * decir "tudo no gadget menos isto".
     */
    function markSelectHolder() {
        var select = widgetSelect();
        if (!select || !select.parentElement) { return; }

        var holder = select.parentElement;
        if (!holder.classList.contains('smsapi-t-holder')) {
            holder.classList.add('smsapi-t-holder');
        }
    }

    /**
     * Remove a atribuição que o Google deixa dentro do widget.
     *
     * O Google escreve-a como nós de texto crus ("Tecnologia do"), seguidos do
     * <span> com o logótipo. Não existe selector CSS que apanhe um nó de
     * texto: esconder o <span> deixava a palavra pendurada por baixo do
     * selector de idioma. O CSS põe-na a zero para não se ver; aqui remove-se
     * de vez, para também não sobrar espaço.
     *
     * A atribuição que a lei e os termos do Google exigem está no rodapé, em
     * todas as páginas.
     */
    function cleanCredits() {
        var gadget = document.querySelector('#google_translate_element .goog-te-gadget');
        if (!gadget) { return; }

        var children = Array.prototype.slice.call(gadget.childNodes);
        for (var i = 0; i < children.length; i++) {
            var node = children[i];

            if (node.nodeType === 3) {
                // Atribuição e espaços em branco: nós de texto não são
                // alcançáveis por selector CSS.
                gadget.removeChild(node);
                continue;
            }

            if (node.nodeType === 1 && !node.querySelector('select.goog-te-combo')) {
                // O <span> com o logótipo e a barra de crédito. Sai tudo o que
                // não for o selector, senão sobra espaço por baixo dele.
                gadget.removeChild(node);
            }
        }
    }

    /** O <select> ja tem opcoes. */
    function widgetReady() {
        var select = widgetSelect();
        return !!select && select.options.length > 0;
    }

    /**
     * O valor que o Google usa para um idioma.
     *
     * As opcoes sao locales ("pt-PT", "en", "fr-CA"), nao codigos curtos. Por
     * isso "pt" so e encontrado em "pt-PT". A ordem de preferencia e:
     * exacto -> lingua base -> primeiro que comece pelo codigo.
     */
    function optionFor(select, code) {
        var i, opt;

        for (i = 0; i < select.options.length; i++) {
            if (select.options[i].value === code) { return code; }
        }
        for (i = 0; i < select.options.length; i++) {
            opt = select.options[i].value;
            if (opt && opt.substring(0, 2).toLowerCase() === code) { return opt; }
        }
        return null;
    }

    /** Aplica o idioma, se o widget tiver opcoes para o fazer. */
    function applyLanguage(code) {
        var inner = widgetSelect();
        if (!inner || inner.options.length === 0) { return false; }

        var wanted = (code === 'auto' || !code) ? pageLang : code;
        var target = optionFor(inner, wanted);

        // A pagina ja esta no idioma pedido: nao se mexe no widget. Sem esta
        // guarda, um navegador pt-PT acabava com lang="pt-PT" e uma
        // "traducao" de portugues para portugues.
        if (wanted === pageLang && target && target.substring(0, 2).toLowerCase() === pageLang) {
            return true;
        }

        if (!target) { return false; }
        if (inner.value === target) { return true; }

        inner.value = target;
        inner.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function syncLangAttribute() {
        var inner = widgetSelect();
        var value = (inner && inner.value) || '';

        // O Google volta a escrever a atribuicao a cada troca de idioma.
        cleanCredits();

        // Sem isto, um navegador pt-PT ficava com lang="pt-PT": o widget põe
        // por omissao a variante regional da pagina e nao ha traducao nenhuma
        // para registar. O atributo so muda quando o idioma e mesmo outro.
        if (!value || value.substring(0, 2).toLowerCase() === pageLang) {
            document.documentElement.setAttribute('lang', pageLang);
            return;
        }
        document.documentElement.setAttribute('lang', value);
    }

    function buildContainer() {
        if (container()) { return true; }

        // Visivel de proposito: o Google so inicializa o widget num contentor
        // que esteja no layout.
        var host = document.createElement('div');
        host.id = 'google_translate_element';
        // O Google nao deve traduzir o proprio selector de idioma.
        host.className = 'notranslate';
        host.setAttribute('translate', 'no');
        slot().appendChild(host);
        return true;
    }

    function loadScript() {
        if (document.querySelector('script[data-smsapi-translate]')) { return; }

        // Optional escape hatch: <meta name="smsapi-translate-src" content="...">
        var override = document.querySelector('meta[name="smsapi-translate-src"]');
        var src = (override && override.getAttribute('content'))
            || 'https://translate.google.com/translate_a/element.js';

        var script = document.createElement('script');
        script.type = 'text/javascript';
        script.async = true;
        script.setAttribute('data-smsapi-translate', '1');
        script.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'cb=googleTranslateElementInit';
        script.onerror = hide;
        document.body.appendChild(script);
    }

    /** Espera pelo widget em vez de adivinhar com um tempo fixo. */
    function waitForWidget(attempt) {
        if (widgetReady()) {
            widgetSelectReady();
            return;
        }

        // O Google cria o invólucro logo, mas as ~249 opcoes só chegam uns
        // segundos depois. Julgar que esta pronto pela existencia do invólucro
        // dava um selector vazio — a dropdown que nao faz nada. Espera-se pelas
        // opcoes, ate ~45 s para uma ligacao lenta.
        if (attempt > 180) {
            hide();
            return;
        }
        setTimeout(function () { waitForWidget(attempt + 1); }, 250);
    }

    var wired = false;

    function widgetSelectReady() {
        var inner = widgetSelect();

        markSelectHolder();

        if (inner && !wired) {
            inner.addEventListener('change', syncLangAttribute);
            wired = true;
        }

        cleanCredits();

        var target = stored() || browserLanguage();
        if (!applyLanguage(target || 'auto')) {
            // O widget ainda nao tem a opcao pretendida. Nao se mexe e
            // espera-se que a proxima sondagem resolva; esconder o slot aqui
            // esconderia um selector perfeitamente funcional.
            return;
        }
        syncLangAttribute();
        show();
    }

    /**
     * someRecurso e howMany (do codigo do Google) sao o gancho documentado
     * para decidir o que fazer mal o widget esteja pronto — e o unico momento
     * em que se sabe que o <select> ja tem opcoes. Sem isto, quem chega
     * primeiro entre o script e a nossa sondagem decide, e a traducao
     * automatica fica ou a nao ser aplicada.
     */
    window.googleTranslateElementInit = function () {
        new google.translate.TranslateElement(
            {
                pageLanguage: pageLang,
                autoDisplay: false
            },
            'google_translate_element',
            function () {
                // Este gancho dispara quando o widget e criado, ainda sem as
                // opcoes. So serve para acelerar a espera; a decisao fica na
                // sondagem, que verifica se ha mesmo opcoes.
                if (widgetReady()) { widgetSelectReady(); }
            }
        );
    };

    function init() {
        if (!slot()) { return; }

        // O slot fica visivel ANTES de carregar o script. Escondi-lo aqui
        // impedia o Google de construir o widget, porque so inicializa num
        // contentor que esteja no layout — o selector nunca aparecia.
        show();
        if (!buildContainer()) { return; }
        loadScript();
        waitForWidget(0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();