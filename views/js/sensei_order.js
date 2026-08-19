/* Sensei - pestaña de pedido. @author ecomlabs */
$(function () {
  var $p = $('#sensei-panel');
  if (!$p.length) return;
  var ajaxUrl = $p.data('ajax'), idOrder = $p.data('order');
  var selected = null; // tarifa elegida
  var t = window.senseiI18n || {};

  function post(action, data) {
    return $.ajax({ url: ajaxUrl, type: 'POST', dataType: 'json', data: $.extend({ ajax: 1, action: action, id_order: idOrder }, data) });
  }
  function alertBox(type, html) { return '<div class="alert alert-' + type + '">' + html + '</div>'; }
  function packages() {
    var arr = [];
    $('#sensei-packages tbody tr').each(function () {
      arr.push({ weight: $(this).find('.sensei-w').val(), l: $(this).find('.sensei-l').val(), w: $(this).find('.sensei-wd').val(), h: $(this).find('.sensei-h').val() });
    });
    return arr;
  }
  function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
  // Carga el PDF de la etiqueta en un iframe oculto y abre el diálogo de impresión del navegador.
  function printLabel(url) {
    $('#sensei-print-frame').remove();
    var f = document.createElement('iframe');
    f.id = 'sensei-print-frame';
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    f.onload = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (e) { window.open(url, '_blank'); } // ponytail: fallback si el navegador bloquea print() en iframe
    };
    f.src = url;
    document.body.appendChild(f);
  }
  $p.on('click', '.sensei-print', function (e) { e.preventDefault(); printLabel($(this).attr('href')); });

  // Paquetes
  $('#sensei-pkg-add').on('click', function () {
    var $row = $('#sensei-packages tbody tr:last').clone();
    $('#sensei-packages tbody').append($row);
  });
  $p.on('click', '.sensei-pkg-del', function () {
    if ($('#sensei-packages tbody tr').length > 1) $(this).closest('tr').remove();
  });
  // COD
  $('#sensei-cod').on('change', function () { $('#sensei-cod-wrap').toggle(this.checked); });
  $('#sensei-pickup').on('change', function () { $('#sensei-pickup-fields').toggle(this.checked); });

  // Cotizar
  $('#sensei-quote').on('click', function () {
    var $b = $(this).prop('disabled', true);
    selected = null; $('#sensei-ship').prop('disabled', true); $('#sensei-points-wrap').hide();
    $('#sensei-rates').html('<span class="text-muted">' + t.quoting + '</span>');
    post('quote', { packages: packages(), insured_amount: $('#sensei-insured').val() }).done(function (r) {
      if (!r.ok) { $('#sensei-rates').html(alertBox('danger', esc(r.error))); return; }
      if (!r.rates.length) { $('#sensei-rates').html(alertBox('warning', t.noRates)); return; }
      var h = '<h4 class="mb-2">' + t.rates + ' <small class="text-muted">(' + r.rates.length + ')</small></h4><table class="table table-hover" id="sensei-rates-table"><thead><tr><th style="width:40px"></th><th>' + t.courier + '</th><th>' + t.service + '</th><th>' + t.delivery + '</th><th class="text-right">' + t.total + '</th></tr></thead><tbody>';
      $.each(r.rates, function (i, q) {
        h += '<tr class="sensei-rate" data-i="' + i + '"><td><div class="md-radio"><label><input type="radio" name="sensei_rate" value="' + i + '"><i class="md-radio-control"></i></label></div></td><td>' + esc(q.courier) + '</td><td>' + esc(q.service) +
          (q.requires_delivery_point ? ' <span class="badge badge-info">' + t.deliveryPoint + '</span>' : '') + (q.requires_pickup_point ? ' <span class="badge badge-info">' + t.pickupPoint + '</span>' : '') +
          '</td><td>' + esc(q.delivery_time || '-') + '</td><td class="text-right"><strong>' + esc(q.total) + ' ' + esc(q.currency) + '</strong></td></tr>';
      });
      $('#sensei-rates').html(h + '</tbody></table>').data('rates', r.rates).data('dest', { postal_code: r.postal_code, city: r.city });
    }).fail(function () { $('#sensei-rates').html(alertBox('danger', t.commError)); })
      .always(function () { $b.prop('disabled', false); });
  });

  $p.on('click', '.sensei-rate', function () {
    var i = $(this).data('i'), rates = $('#sensei-rates').data('rates');
    $(this).find('input').prop('checked', true);
    $('.sensei-rate').removeClass('table-active'); $(this).addClass('table-active');
    selected = rates[i];
    $('#sensei-ship').prop('disabled', false);
    $('#sensei-points-wrap').hide(); $('#sensei-point').empty();
    if (selected.requires_delivery_point || selected.requires_pickup_point) {
      var dest = $('#sensei-rates').data('dest');
      var path = selected.delivery_points_url || '/api/v1/seur/pickup-points/';
      // ponytail: el punto de origen (SEUR 2Shop) se busca por el CP del destinatario también; cambia a CP del remitente si lo usas.
      $('#sensei-points-wrap').show(); $('#sensei-point').html('<option>' + t.loadingPoints + '</option>');
      post('points', { path: path, postal_code: dest.postal_code, city: dest.city }).done(function (r) {
        if (!r.ok) { $('#sensei-point').html('<option value="">' + esc(r.error) + '</option>'); return; }
        var o = '';
        $.each(r.points, function (_, pt) { o += '<option value="' + esc(pt.code) + '">' + esc(pt.label) + '</option>'; });
        $('#sensei-point').html(o || '<option value="">' + t.noPoints + '</option>');
      });
    }
  });

  // Enviar + recogida
  $('#sensei-ship').on('click', function () {
    if (!selected) return;
    var $b = $(this);
    var force = $b.data('force') ? 1 : 0;
    $b.data('force', 0).prop('disabled', true);
    $('#sensei-result').html('<span class="text-muted">' + t.creating + '</span>');
    var data = {
      packages: packages(),
      service_id: selected.service_id,
      courier_name: selected.courier, service_name: selected.service, service_total: selected.total,
      cod_enabled: $('#sensei-cod').is(':checked') ? 1 : 0, cod_amount: $('#sensei-cod-amount').val(),
      insured_amount: $('#sensei-insured').val(),
      force: force,
      pickup_enabled: $('#sensei-pickup').is(':checked') ? 1 : 0,
      pickup_date: $('#sensei-pickup-date').val(), pickup_from: $('#sensei-pickup-from').val(), pickup_to: $('#sensei-pickup-to').val(), pickup_notes: $('#sensei-pickup-notes').val()
    };
    if (selected.requires_delivery_point) data.delivery_point_code = $('#sensei-point').val();
    if (selected.requires_pickup_point) data.origin_pickup_point_code = $('#sensei-point').val();
    post('ship', data).done(function (r) {
      if (!r.ok) {
        if (r.duplicate) {
          $('#sensei-result').html(alertBox('warning', esc(r.error) + ' <button type="button" class="btn btn-sm btn-outline-danger ml-2" id="sensei-force">' + t.forceShip + '</button>'));
        } else {
          $('#sensei-result').html(alertBox('danger', esc(r.error)));
        }
        $b.prop('disabled', false); return;
      }
      if (r.row_html) {
        $('#sensei-shipments').show().prev('.alert').remove();
        $('#sensei-shipments tbody').prepend(r.row_html);
        $('#sensei-shipments').next('hr').show();
        $('#sensei-panel .card-header-title').text(t.shipments + ' (' + $('#sensei-shipments tbody tr').length + ')').prepend('<i class="material-icons">local_shipping</i> ');
      }
      var h = '<strong>' + t.created + '</strong> Tracking: <code>' + esc(r.tracking_number) + '</code> ' +
        '<a class="btn btn-sm btn-outline-secondary ml-2 sensei-print" href="' + r.label_url + '"><i class="material-icons">print</i> ' + t.label + '</a>';
      if (r.pickup_code) h += '<br>' + t.pickupScheduled + ' <code>' + esc(r.pickup_code) + '</code>.';
      if (r.pickup_message) h += '<br><small>' + esc(r.pickup_message) + '</small>';
      h += '<br><a href="#" class="sensei-reload">' + t.reload + '</a> ' + t.reloadHint;
      $('#sensei-result').html(alertBox(data.pickup_enabled && !r.pickup_code ? 'warning' : 'success', h));
      printLabel(r.label_url);
    }).fail(function () { $('#sensei-result').html(alertBox('danger', t.commError)); $b.prop('disabled', false); });
  });

  $p.on('click', '#sensei-force', function () { $('#sensei-ship').data('force', 1).trigger('click'); });
  $p.on('click', '.sensei-reload', function (e) { e.preventDefault(); location.reload(); });

  // Cancelar / tracking de envíos existentes
  $p.on('click', '.sensei-cancel', function () {
    var $tr = $(this).closest('tr');
    if (!window.confirm(t.confirmCancel)) return;
    post('cancel', { uuid: $tr.data('uuid') }).done(function (r) {
      if (!r.ok) { window.showErrorMessage ? showErrorMessage(r.error) : alert(r.error); return; }
      $tr.find('.sensei-status').html('<span class="badge badge-danger">cancelled</span>'); $tr.find('button, a.btn').remove();
      window.showSuccessMessage && showSuccessMessage(r.message);
    });
  });
  $p.on('click', '.sensei-track', function () {
    var $tr = $(this).closest('tr');
    post('tracking', { tracking_number: $(this).data('tracking') }).done(function (r) {
      if (!r.ok) { window.showErrorMessage ? showErrorMessage(r.error) : alert(r.error); return; }
      $tr.find('.sensei-status').html('<span class="badge badge-info">' + esc(r.status) + '</span><br><small class="text-muted">' + r.history.slice(0, 3).map(esc).join('<br>') + '</small>');
    });
  });
});
