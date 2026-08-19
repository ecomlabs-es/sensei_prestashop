{**
 * Sensei - card en el detalle de pedido
 * @author ecomlabs
 *}
<script>var senseiI18n = {
  quoting: '{l s='Quoting…' mod='sensei' js=1}',
  noRates: '{l s='No rates available for this destination.' mod='sensei' js=1}',
  rates: '{l s='Available rates' mod='sensei' js=1}',
  courier: '{l s='Courier' mod='sensei' js=1}',
  service: '{l s='Service' mod='sensei' js=1}',
  delivery: '{l s='Delivery time' mod='sensei' js=1}',
  total: '{l s='Total' mod='sensei' js=1}',
  deliveryPoint: '{l s='delivery point' mod='sensei' js=1}',
  pickupPoint: '{l s='origin point' mod='sensei' js=1}',
  loadingPoints: '{l s='Loading points…' mod='sensei' js=1}',
  noPoints: '{l s='No nearby points' mod='sensei' js=1}',
  creating: '{l s='Creating shipment…' mod='sensei' js=1}',
  forceShip: '{l s='Create another shipment anyway' mod='sensei' js=1}',
  created: '{l s='Shipment created.' mod='sensei' js=1}',
  label: '{l s='Label' mod='sensei' js=1}',
  pickupScheduled: '{l s='Pickup scheduled, code' mod='sensei' js=1}',
  reload: '{l s='Reload the page' mod='sensei' js=1}',
  reloadHint: '{l s='to see the new order status.' mod='sensei' js=1}',
  commError: '{l s='Communication error.' mod='sensei' js=1}',
  confirmCancel: '{l s='Cancel this shipment in Sensei?' mod='sensei' js=1}',
  shipments: '{l s='Sensei - Shipments' mod='sensei' js=1}'
};</script>
<div class="card mt-2" id="sensei-panel" data-ajax="{$sensei_ajax|escape:'html':'UTF-8'}" data-order="{$sensei_order->id|intval}">
  <div class="card-header">
    <h3 class="card-header-title"><i class="material-icons">local_shipping</i> {l s='Sensei - Shipments' mod='sensei'} ({$sensei_shipments|count})</h3>
  </div>
  <div class="card-body">
    {if !$sensei_has_token}
      <div class="alert alert-warning">{l s='Set the API Token in Modules &gt; Sensei.' mod='sensei'}</div>
    {/if}

    {* ---- Envíos existentes ---- *}
    <table class="table" id="sensei-shipments" {if !$sensei_shipments}style="display:none"{/if}>
      <thead>
        <tr><th>{l s='Date' mod='sensei'}</th><th>{l s='Courier / service' mod='sensei'}</th><th>{l s='Tracking' mod='sensei'}</th><th>{l s='Cost' mod='sensei'}</th><th>{l s='COD' mod='sensei'}</th><th>{l s='Pickup' mod='sensei'}</th><th>{l s='Status' mod='sensei'}</th><th></th></tr>
      </thead>
      <tbody>
      {foreach $sensei_shipments as $s}
        {include file='./shipment_row.tpl' s=$s}
      {/foreach}
      </tbody>
    </table>
    <hr {if !$sensei_shipments}style="display:none"{/if}>

    {* ---- Nuevo envío ---- *}
    <div class="row">
      {* Columna izquierda: destino + opciones *}
      <div class="col-lg-4">
        <h4 class="mb-2">{l s='Recipient' mod='sensei'}</h4>
        <address class="mb-3">
          <strong>{$sensei_dest.name|escape:'html':'UTF-8'}</strong>{if $sensei_dest.company} ({$sensei_dest.company|escape:'html':'UTF-8'}){/if}<br>
          {$sensei_dest.address|escape:'html':'UTF-8'}<br>
          {$sensei_dest.cp|escape:'html':'UTF-8'} {$sensei_dest.city|escape:'html':'UTF-8'} ({$sensei_dest.country|escape:'html':'UTF-8'})<br>
          <i class="material-icons">phone</i> {$sensei_dest.phone|escape:'html':'UTF-8'}<br>
          <i class="material-icons">email</i> {$sensei_dest.email|escape:'html':'UTF-8'}
        </address>

        <div class="form-group">
          <div class="md-checkbox">
            <label>
              <input type="checkbox" id="sensei-cod" {if $sensei_is_cod}checked{/if}>
              <i class="md-checkbox-control"></i>
              {l s='Cash on delivery' mod='sensei'} {if $sensei_is_cod}<span class="badge badge-warning ml-1">{l s='COD order' mod='sensei'}</span>{/if}
            </label>
          </div>
          <div id="sensei-cod-wrap" {if !$sensei_is_cod}style="display:none"{/if}>
            <div class="input-group">
              <input type="number" step="0.01" min="0" class="form-control" id="sensei-cod-amount" value="{$sensei_cod_amount|escape:'html':'UTF-8'}">
              <div class="input-group-append"><span class="input-group-text">€</span></div>
            </div>
            <small class="form-text text-muted">{l s='Amount to collect on delivery' mod='sensei'}</small>
          </div>
        </div>

        <div class="form-group">
          <label class="form-control-label" for="sensei-insured">{l s='Insured value' mod='sensei'}</label>
          <div class="input-group">
            <input type="number" step="0.01" min="0" class="form-control" id="sensei-insured" placeholder="0.00">
            <div class="input-group-append"><span class="input-group-text">€</span></div>
          </div>
          <small class="form-text text-muted">{l s='Optional. Adds insurance and filters compatible services.' mod='sensei'}</small>
        </div>

        <div class="form-group">
          <div class="md-checkbox">
            <label>
              <input type="checkbox" id="sensei-pickup" checked>
              <i class="md-checkbox-control"></i>
              {l s='Schedule pickup' mod='sensei'}
            </label>
          </div>
          <div id="sensei-pickup-fields">
            <input type="date" class="form-control mb-1" id="sensei-pickup-date" value="{$sensei_pickup_date|escape:'html':'UTF-8'}">
            <div class="input-group mb-1">
              <input type="time" class="form-control" id="sensei-pickup-from" value="{$sensei_pickup_from|escape:'html':'UTF-8'}">
              <div class="input-group-prepend input-group-append"><span class="input-group-text">a</span></div>
              <input type="time" class="form-control" id="sensei-pickup-to" value="{$sensei_pickup_to|escape:'html':'UTF-8'}">
            </div>
            <input type="text" class="form-control" id="sensei-pickup-notes" placeholder="{l s='Notes for the courier' mod='sensei'}">
          </div>
        </div>
      </div>

      {* Columna derecha: paquetes + tarifas + acción *}
      <div class="col-lg-8">
        <h4 class="mb-2">{l s='Packages' mod='sensei'}</h4>
        <table class="table table-sm" id="sensei-packages">
          <thead><tr><th>{l s='Weight (kg)' mod='sensei'}</th><th>{l s='Length (cm)' mod='sensei'}</th><th>{l s='Width (cm)' mod='sensei'}</th><th>{l s='Height (cm)' mod='sensei'}</th><th class="text-right"><button type="button" class="btn btn-outline-secondary btn-sm" id="sensei-pkg-add" title="{l s='Add package' mod='sensei'}"><i class="material-icons">add</i></button></th></tr></thead>
          <tbody>
            <tr>
              <td><input type="number" step="0.01" min="0.01" class="form-control sensei-w" value="{$sensei_weight|escape:'html':'UTF-8'}"></td>
              <td><input type="number" step="1" class="form-control sensei-l" value="{$sensei_dims[0]|escape:'html':'UTF-8'}"></td>
              <td><input type="number" step="1" class="form-control sensei-wd" value="{$sensei_dims[1]|escape:'html':'UTF-8'}"></td>
              <td><input type="number" step="1" class="form-control sensei-h" value="{$sensei_dims[2]|escape:'html':'UTF-8'}"></td>
              <td class="text-right"><button type="button" class="btn btn-outline-danger btn-sm sensei-pkg-del" title="{l s='Remove' mod='sensei'}"><i class="material-icons">delete</i></button></td>
            </tr>
          </tbody>
        </table>

        <div class="d-flex align-items-center mb-3">
          <button type="button" class="btn btn-primary" id="sensei-quote"><i class="material-icons">euro_symbol</i> {l s='Get quotes' mod='sensei'}</button>
          <span class="text-muted ml-3" id="sensei-quote-hint">{l s='Only home delivery services are shown.' mod='sensei'}</span>
        </div>

        <div id="sensei-rates"></div>

        <div id="sensei-points-wrap" class="form-group" style="display:none">
          <label class="form-control-label">{l s='Delivery / pickup point' mod='sensei'} <span class="text-danger">*</span></label>
          <select class="form-control" id="sensei-point"></select>
        </div>

        <div class="mt-3">
          <button type="button" class="btn btn-success btn-lg" id="sensei-ship" disabled><i class="material-icons">local_shipping</i> {l s='Ship and schedule pickup' mod='sensei'}</button>
          <small class="form-text text-muted">{l s='Creates the shipment in Sensei, downloads the label, schedules the pickup and updates the order tracking and status.' mod='sensei'}</small>
        </div>
        <div id="sensei-result" class="mt-3"></div>
      </div>
    </div>
  </div>
</div>
