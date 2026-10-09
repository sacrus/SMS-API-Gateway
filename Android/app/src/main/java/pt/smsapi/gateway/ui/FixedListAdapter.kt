package pt.smsapi.gateway.ui

import android.content.Context
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.Filter
import android.widget.Filterable

/**
 * Adaptador para listas fixas em caixas de selecção do Material.
 *
 * O MaterialAutoCompleteTextView é um campo de texto com preenchimento
 * automático: ao abrir, filtra as opções pelo texto que já lá está. Com uma
 * lista de idiomas ou de cartões SIM isso é errado — Depois de escolher
 * "English", o campo ficava com esse texto e a lista mostrava apenas
 * "English", sem forma de mudar.
 *
 * Um filtro que devolve sempre a lista inteira desliga esse comportamento,
 * mantendo o aspecto Material.
 */
class FixedListAdapter(
    context: Context,
    private val labels: List<String>
) : ArrayAdapter<String>(context, android.R.layout.simple_list_item_1, labels),
    Filterable {

    private val passThroughFilter = object : Filter() {
        override fun performFiltering(constraint: CharSequence?): FilterResults {
            val results = FilterResults()
            results.values = labels.toList()
            results.count = labels.size
            return results
        }

        override fun publishResults(constraint: CharSequence?, results: FilterResults?) {
            // Nada a publicar: o getView() do adaptador desenha sempre a lista.
        }
    }

    override fun getFilter(): Filter = passThroughFilter

    override fun getView(position: Int, convertView: View?, parent: ViewGroup): View {
        val view = convertView ?: LayoutInflater.from(context)
            .inflate(android.R.layout.simple_dropdown_item_1line, parent, false)
        (view as? android.widget.TextView)?.text = labels[position]
        return view
    }
}