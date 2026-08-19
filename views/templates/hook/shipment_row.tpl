{* Fila de envío (usada en la card y en la respuesta ajax) *}
        <tr data-uuid="{$s.uuid|escape:'html':'UTF-8'}">
          <td>{$s.date_add|date_format:'%d/%m/%Y %H:%M'}</td>
          <td>{$s.courier|escape:'html':'UTF-8'}<br><small class="text-muted">{$s.service|escape:'html':'UTF-8'}</small></td>
          <td><code>{$s.tracking_number|escape:'html':'UTF-8'}</code></td>
          <td>{$s.total|escape:'html':'UTF-8'} €</td>
          <td>{if $s.cod_amount}{$s.cod_amount|escape:'html':'UTF-8'} €{else}-{/if}</td>
          <td>{if $s.pickup_code}<code>{$s.pickup_code|escape:'html':'UTF-8'}</code><br><small class="text-muted">{$s.pickup_date|date_format:'%d/%m/%Y'}</small>{else}-{/if}</td>
          <td class="sensei-status">
            <span class="badge {if $s.status|in_array:$sensei_delivered}badge-success{elseif $s.status == 'cancelled' || $s.status == 'cancelado' || $s.status == 'incidencia'}badge-danger{else}badge-info{/if}">{$s.status|escape:'html':'UTF-8'}</span>
          </td>
          <td class="text-right text-nowrap">
            {if $s.status != 'cancelled'}
            <a class="btn btn-outline-secondary btn-sm" target="_blank" href="{$sensei_ajax|escape:'html':'UTF-8'}&action=label&uuid={$s.uuid|escape:'url'}" title="{l s='PDF label' mod='sensei'}"><i class="material-icons">print</i> {l s='Label' mod='sensei'}</a>
            <button type="button" class="btn btn-outline-secondary btn-sm sensei-track" data-tracking="{$s.tracking_number|escape:'html':'UTF-8'}" title="{l s='Refresh tracking' mod='sensei'}"><i class="material-icons">refresh</i></button>
            <button type="button" class="btn btn-outline-danger btn-sm sensei-cancel" title="{l s='Cancel shipment' mod='sensei'}"><i class="material-icons">cancel</i></button>
            {/if}
          </td>
        </tr>
