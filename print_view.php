<?php
// Simple print view page that expects POSTed data
// Inputs: voters_json (JSON array), lat, lon, radius_meters, address, radius, county, party

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$voters = [];
if (!empty($_POST['voters_json'])) {
    $decoded = json_decode($_POST['voters_json'], true);
    if (is_array($decoded)) { $voters = $decoded; }
}

$lat = isset($_POST['lat']) ? floatval($_POST['lat']) : 0;
$lon = isset($_POST['lon']) ? floatval($_POST['lon']) : 0;
$radius_m = isset($_POST['radius_meters']) ? floatval($_POST['radius_meters']) : 0;

$address = $_POST['address'] ?? '';
$radius = $_POST['radius'] ?? '';
$county = $_POST['county'] ?? '';
$party  = $_POST['party'] ?? '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Voter Print View</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
  <style>
    @media print {
      @page { size: Letter landscape; margin: 0.5in; }
      /* Help browsers render map tiles with full colors in print */
      .leaflet-container { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    html, body { margin: 0; padding: 0; }
    body { font-size: 12px; }
    /* Constrain the page width to the printable content area of US Letter landscape (10in) */
    #page { width: 10in; max-width: 10in; margin: 0 auto; }
    #printMap { width: 100%; height: 420px; margin-bottom: 12px; border: 1px solid #ddd; }
    table { font-size: 12px; table-layout: fixed; width: 100%; }
    th, td { white-space: nowrap; vertical-align: top; }
    td.wrap, th.wrap { white-space: normal; overflow-wrap: anywhere; }
    thead th { font-size: 11px; }
    thead { display: table-header-group; }
    tr, td, th { page-break-inside: avoid; }
    .meta { font-size: 12px; color: #555; }
  </style>
</head>
<body class="p-3">
  <div id="page">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h5 class="mb-0">Voter Radius Map</h5>
    <button class="btn btn-sm btn-primary" onclick="window.print()">Print</button>
  </div>
  <div class="meta mb-2">
    <div><strong>Address:</strong> <?= h($address) ?></div>
    <div><strong>County:</strong> <?= h($county) ?><?= $party ? ', ' . h($party) : '' ?></div>
    <div><strong>Radius:</strong> <?= h($radius) ?> miles</div>
    <div><strong>Generated:</strong> <?= date('Y-m-d H:i') ?></div>
  </div>
  <div id="printMap"></div>

  <h6 class="mt-3">Voter List</h6>
  <div class="table-responsive">
    <table class="table table-striped table-bordered mb-0">
      <colgroup>
        <col style="width:9%">   <!-- ID/Party -->
        <col style="width:15%">  <!-- Name -->
        <col style="width:26%">  <!-- Address -->
        <col style="width:10%">  <!-- Phone -->
        <col style="width:6%">   <!-- DOB -->
        <col style="width:14%">  <!-- Email -->
        <col style="width:20%">  <!-- Notes -->
      </colgroup>
      <thead class="table-light">
        <tr>
          <th>ID / Party</th>
          <th class="wrap">Name</th>
          <th class="wrap">Address</th>
          <th>Phone</th>
          <th>DOB</th>
          <th class="wrap">Email</th>
          <th class="wrap">Notes</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($voters as $v): ?>
        <tr>
          <td><?php
            $id = trim((string)($v['VoterID'] ?? ''));
            $pt = strtoupper(trim((string)($v['Party'] ?? '')));
            echo h($id) . ($pt ? '<br>' . h($pt) : '');
          ?></td>
          <td class="wrap"><?php
            $ln = trim($v['Last_Name'] ?? '');
            $fn = trim($v['First_Name'] ?? '');
            echo h($ln) . ',<br>' . h($fn);
          ?></td>
          <td class="wrap"><?= nl2br(h($v['Voter_Address'] ?? '')) ?></td>
          <td>
            <?php
              $p = preg_replace('/[^0-9]/', '', $v['Phone_Number'] ?? '');
              if (strlen($p) === 10) {
                  echo '(' . substr($p,0,3) . ')<br>' . substr($p,3,3) . '-' . substr($p,6);
              } else {
                  echo h($v['Phone_Number'] ?? '');
              }
            ?>
          </td>
          <td><?= h(!empty($v['Birthday']) ? date('m/d', strtotime($v['Birthday'])) : '') ?></td>
          <td class="wrap"><?php
            $em = trim($v['Email_Address'] ?? '');
            if ($em && strpos($em, '@') !== false) {
              list($user, $domain) = explode('@', $em, 2);
              echo h($user) . '<br>@' . h($domain);
            } else {
              echo h($em);
            }
          ?></td>
          <td class="wrap">&nbsp;</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>

  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  <script>
    (function(){
      var lat = <?= json_encode($lat) ?>;
      var lon = <?= json_encode($lon) ?>;
      var r = <?= json_encode($radius_m) ?>; // meters
      var pvoters = <?= json_encode($voters) ?>;
      function partyColor(p){
        var x = (p||'').toString().toUpperCase();
        if (x === 'DEM') return '#1976d2';
        if (x === 'REP') return '#d32f2f';
        if (x === 'NPA') return '#616161';
        return '#8e8e8e';
      }
      try {
        // Initialize with a safe default view to avoid blank map before fitting
        var map = L.map('printMap', { zoomControl: false, attributionControl: false }).setView([lat || 0, lon || 0], 14);
        var base = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '' });
        base.on('load', function(){ try { map.invalidateSize(true); } catch(_) {} });
        base.addTo(map);
        var circle = L.circle([lat, lon], { radius: r, color: 'red', fillOpacity: 0.2 }).addTo(map);

        // Plot house pins and collect bounds
        var layer = L.layerGroup().addTo(map);
        var bounds = circle.getBounds();
        try {
          if (Array.isArray(pvoters)) {
            for (var i=0;i<pvoters.length;i++){
              var v = pvoters[i];
              var vlat = v && v.Latitude != null ? Number(v.Latitude) : NaN;
              var vlon = v && v.Longitude != null ? Number(v.Longitude) : NaN;
              if (!isNaN(vlat) && !isNaN(vlon)) {
                var ll = L.latLng(vlat, vlon);
                var cm = L.circleMarker(ll, {radius: 4, weight: 1, color: '#333', fillColor: partyColor(v && v.Party), fillOpacity: 0.95});
                layer.addLayer(cm);
                try { bounds = bounds.extend(ll); } catch(_) {}
              }
            }
          }
        } catch(_) {}

        function fallbackZoomCenter() {
          try {
            var w = document.getElementById('printMap').clientWidth || 1000;
            var targetMeters = (r||0) * 2;
            if (!targetMeters) { map.setView([lat, lon], 14); return; }
            var bestZ = 14;
            for (var z = 18; z >= 3; z--) {
              var mpp = 156543.03392 * Math.cos(lat * Math.PI/180) / Math.pow(2, z);
              if (mpp * (w * 0.85) >= targetMeters) { bestZ = z; }
            }
            map.setView([lat, lon], bestZ);
          } catch(_) { map.setView([lat, lon], 14); }
        }

        function fitAll() {
          try {
            if (bounds && bounds.isValid && bounds.isValid()) {
              map.fitBounds(bounds.pad(0.18));
            } else {
              fallbackZoomCenter();
            }
          } catch(_) { fallbackZoomCenter(); }
        }


        // Ensure map has correct size before fitting
        map.whenReady(function(){
          try { map.invalidateSize(true); } catch(_) {}
          setTimeout(function(){ try { map.invalidateSize(true); fitAll(); } catch(_) { fallbackZoomCenter(); } }, 120);
          setTimeout(function(){ try { map.invalidateSize(true); fitAll(); } catch(_) {} }, 400);
        });

        // Also refit after fonts/tiles load
        window.addEventListener('load', function(){ setTimeout(function(){ try { map.invalidateSize(true); fitAll(); } catch(_) {} }, 150); });

        // Refit around print without changing zoom center too aggressively
        (function(){
          function doFitPrint(){
            try {
              map.options.zoomAnimation = false;
              map.options.fadeAnimation = false;
              map.options.markerZoomAnimation = false;
              map.invalidateSize(true);
              fitAll();
            } catch(_) {}
            setTimeout(function(){ try { map.invalidateSize(true); fitAll(); } catch(_) {} }, 120);
          }
          function doFitAfter(){ try { map.invalidateSize(true); fitAll(); } catch(_) {} }
          if ('onbeforeprint' in window) {
            window.addEventListener('beforeprint', doFitPrint);
            window.addEventListener('afterprint', doFitAfter);
          }
          var mql = window.matchMedia && window.matchMedia('print');
          if (mql) {
            var onChange = function(e){ if (e.matches) { doFitPrint(); } else { doFitAfter(); } };
            if (mql.addEventListener) mql.addEventListener('change', onChange);
            else if (mql.addListener) mql.addListener(onChange);
          }
        })();
      } catch(e) {}
    })();
  </script>
</body>
</html>
