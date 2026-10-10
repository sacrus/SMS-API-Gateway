<?php

declare(strict_types=1);

namespace SmsApi;

use RuntimeException;
use Throwable;

/**
 * Verificação de que a base de dados não está descarregável pela web.
 *
 * Esta é a falha mais grave possível nesta aplicação: o ficheiro SQLite tem
 * dentro os hashes das palavras-passe, a chave mestra da API, os números de
 * telefone e o texto das mensagens. Se alguém o descarregar, não há nada que o
 * PHP possa fazer depois — quem o serve é o servidor web.
 *
 * A verificação não faz pedidos de rede: vê a configuração em disco e o tipo de
 * servidor (ver Installer::storageExposed). É instantânea, não pode ser
 * estrangulada por alguém que controle o cabeçalho Host e não trava em
 * servidores de um só processo.
 *
 * Só corre no painel, com sessão iniciada. Numa página pública bastava uma
 * visita de qualquer pessoa para disparar isto.
 *
 * O administrador pode dispensar o aviso quando já confirmou a protecção. A
 * dispensa fica presa ao estado em que foi dada: se a situação mudar, o aviso
 * volta. Esconder um aviso para sempre seria apagar a única coisa que avisa
 * quando o ficheiro passa a estar exposto.
 */
final class SecurityCheck
{
    private static bool $started = false;

    /**
     * Resultado desta visita. Ver correr a verificação duas vezes por página era
     * lento e podia dar resultados diferentes, porque o servidor nem sempre
     * responde igual a dois pedidos seguidos.
     *
     * @var array{checked:bool, exposed:bool, unconfirmed:bool, url:string, note:?string}|null
     */
    private static ?array $ultimo = null;

    public static function start(): void
    {
        if (self::$started || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        self::$started = true;

        // Só faz sentido no painel. Numa página pública ou no login, qualquer
        // pessoa a chegar bastava para disparar isto.
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if (!str_ends_with($script, '/index.php') || !str_contains($script, '/admin/')) {
            return;
        }
        if (empty($_SESSION['admin_user_id'])) {
            return;
        }

        // Um aviso de segurança nunca pode ser a razão de o painel deixar de
        // abrir. Se a verificação falhar por qualquer razão, o painel continua
        // a funcionar sem o aviso e a falha fica registada.
        try {
            $probe = self::run();
        } catch (Throwable $e) {
            error_log('SMSAPI: falha na verificação de segurança: ' . $e->getMessage());

            return;
        }

        self::$ultimo = $probe;
        self::aplicar($probe);
    }

    /**
     * O aviso a mostrar nesta visita, ou null se não houver nada a mostrar.
     *
     * @return array{tipo:string, titulo:string}|null
     */
    public static function aviso(): ?array
    {
        if (!empty($_SESSION['smsapi_db_aviso_dispensado'])) {
            return null;
        }

        $probe = self::$ultimo;

        if (!empty($probe['exposed'])) {
            return ['tipo' => 'error', 'titulo' => 'Segurança: a base de dados pode ser descarregada pela web.'];
        }

        if (!empty($probe['unconfirmed'])) {
            return ['tipo' => 'warn', 'titulo' => 'Segurança: a protecção da base de dados não pôde ser confirmada.'];
        }

        return null;
    }

    /**
     * Corre a verificação e devolve a frase que se mostra ao administrador.
     *
     * Falha nunca é fatal: quem está a ver isto é o painel, e um aviso de
     * segurança não pode ser a razão de o painel deixar de abrir.
     *
     * @return array{estado:string, tipo:string, mensagem:string}
     */
    public static function verificar(): array
    {
        // Este botão é o mecanismo explícito para voltar a ver o aviso: quem
        // pede a verificação quer ver o resultado, mesmo que tenha dispensado o
        // aviso antes. Tira a dispensa do disco, senão a linha seguinte a
        // voltava a ler de lá e o botão não repunha nada.
        @unlink(self::dispensaPath());
        unset($_SESSION['smsapi_db_aviso_dispensado'], $_SESSION['smsapi_db_dispensado_estado']);

        try {
            $probe = self::run();
        } catch (Throwable $e) {
            error_log('SMSAPI: falha na verificação de segurança: ' . $e->getMessage());
            unset($_SESSION['smsapi_db_exposta'], $_SESSION['smsapi_db_por_confirmar']);

            return [
                'estado' => 'erro',
                'tipo' => 'error',
                'mensagem' => 'Não foi possível verificar a pasta de dados: ' . $e->getMessage(),
            ];
        }

        self::$ultimo = $probe;
        self::aplicar($probe);

        $nota = (string) ($probe['note'] ?? '');

        if (!empty($probe['exposed'])) {
            return ['estado' => 'exposta', 'tipo' => 'error', 'mensagem' => 'A base de dados continua descarregável. ' . $nota];
        }

        if (empty($probe['checked'])) {
            return ['estado' => 'protegida', 'tipo' => 'ok', 'mensagem' => 'Tudo bem: ' . ($nota ?: 'a base de dados está fora da pasta web.')];
        }

        if (!empty($probe['unconfirmed'])) {
            return ['estado' => 'por_confirmar', 'tipo' => 'warn', 'mensagem' => 'Não é possível confirmar pelo PHP: ' . $nota];
        }

        return ['estado' => 'protegida', 'tipo' => 'ok', 'mensagem' => 'Configuração verificada: ' . $nota];
    }

    /**
     * Regista que o administrador já confirmou a protecção e não quer ver mais
     * o aviso.
     *
     * Fica em ficheiro, e não na sessão: a sessão morre no logout, e um
     * administrador que confirmou a situação não quer voltar a vê-la a cada
     * entrada. O ficheiro é do gateway, não de uma sessão, por isso sobrevive.
     */
    public static function dispensar(): void
    {
        $probe = self::run();

        self::$ultimo = $probe;
        self::aplicar($probe);

        @file_put_contents(self::dispensaPath(), json_encode([
            'estado' => self::estadoAtual($probe),
            'quando' => time(),
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);

        $_SESSION['smsapi_db_aviso_dispensado'] = true;
        $_SESSION['smsapi_db_dispensado_estado'] = self::estadoAtual($probe);
    }

    /**
     * A dispensa guardada em disco, se houver.
     *
     * @return array{estado:string, quando:int}|null
     */
    private static function dispensaGuardada(): ?array
    {
        $path = self::dispensaPath();
        if (!is_file($path)) {
            return null;
        }
        $dados = json_decode((string) @file_get_contents($path), true);

        return is_array($dados) && isset($dados['estado']) ? $dados : null;
    }

    private static function dispensaPath(): string
    {
        return SMSAPI_ROOT . '/storage/.aviso-dispensado.json';
    }

    /**
     * @return array{checked:bool, exposed:bool, unconfirmed:bool, url:string, note:?string}
     */
    public static function run(): array
    {
        $config = Config::load();
        if (!is_array($config)) {
            // O config.php existe mas não devolve um array: está malformado ou foi
            // truncado. Não é para isto fazer o painel devolver 500.
            throw new RuntimeException('O config.php não devolve uma configuração válida.');
        }

        return Installer::storageExposed(SMSAPI_ROOT, $config['db'] ?? [], self::gatewayBaseUrl($config));
    }

    /**
     * @param array{checked:bool, exposed:bool, unconfirmed:bool, url:string, note:?string} $probe
     */
    private static function aplicar(array $probe): void
    {
        $_SESSION['smsapi_db_nota'] = (string) ($probe['note'] ?? '');

        if (!empty($probe['exposed'])) {
            $_SESSION['smsapi_db_exposta'] = true;
            unset($_SESSION['smsapi_db_por_confirmar']);
        } elseif (!empty($probe['unconfirmed'])) {
            unset($_SESSION['smsapi_db_exposta']);
            $_SESSION['smsapi_db_por_confirmar'] = true;
        } else {
            unset($_SESSION['smsapi_db_exposta'], $_SESSION['smsapi_db_por_confirmar']);
        }

        // Se o estado mudou, a dispensa já não vale: o que mudou é precisamente
        // aquilo que o administrador precisa de saber.
        $dispensa = self::dispensaGuardada();
        if ($dispensa !== null && (string) $dispensa['estado'] !== self::estadoAtual($probe)) {
            @unlink(self::dispensaPath());
            unset($_SESSION['smsapi_db_aviso_dispensado'], $_SESSION['smsapi_db_dispensado_estado']);

            return;
        }

        if ($dispensa !== null) {
            $_SESSION['smsapi_db_aviso_dispensado'] = true;
            $_SESSION['smsapi_db_dispensado_estado'] = (string) $dispensa['estado'];
        }
    }

    /**
     * @param array{checked:bool, exposed:bool, unconfirmed:bool, url:string, note:?string} $probe
     */
    private static function estadoAtual(array $probe): string
    {
        if (!empty($probe['exposed'])) {
            return 'exposta';
        }

        if (!empty($probe['unconfirmed'])) {
            return 'por_confirmar';
        }

        return 'protegida';
    }

    /**
     * Endereço da raiz do gateway, a partir do que está guardado no config.
     *
     * Serve só para mostrar ao administrador qual é o endereço perigoso. Usa-se
     * o `public_base_url` e não a pasta do script que está a correr: chamado a
     * partir de `admin/index.php`, o dirname dava `/smsapi/admin` e o endereço
     * ficava com `/admin/` a mais.
     */
    private static function gatewayBaseUrl(array $config): string
    {
        $public = rtrim((string) ($config['public_base_url'] ?? ''), '/');
        if ($public !== '') {
            return str_ends_with($public, '/public')
                ? substr($public, 0, -strlen('/public'))
                : $public;
        }

        return '';
    }
}