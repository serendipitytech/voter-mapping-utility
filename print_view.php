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
    }
    body { font-size: 12px; }
    #printMap { width: 100%; height: 420px; margin-bottom: 12px; border: 1px solid #ddd; }
    table { font-size: 12px; table-layout: fixed; }
    th, td { white-space: nowrap; vertical-align: top; }
    thead { display: table-header-group; }
    tr, td, th { page-break-inside: avoid; }
    .meta { font-size: 12px; color: #555; }
  </style>
</head>
<body class="p-3">
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
      <thead class="table-light">
        <tr>
          <th>Voter ID</th>
          <th>Name</th>
          <th>Address</th>
          <th>Phone</th>
          <th>DOB</th>
          <th>Email</th>
          <th>Party</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($voters as $v): ?>
        <tr>
          <td><?= h($v['VoterID'] ?? '') ?></td>
          <td><?= h(($v['Last_Name'] ?? '') . ', ' . ($v['First_Name'] ?? '')) ?></td>
          <td><?= nl2br(h($v['Voter_Address'] ?? '')) ?></td>
          <td>
            <?php
              $p = preg_replace('/[^0-9]/', '', $v['Phone_Number'] ?? '');
              if (strlen($p) === 10) {
                  echo '(' . substr($p,0,3) . ') ' . substr($p,3,3) . '-' . substr($p,6);
              } else {
                  echo h($v['Phone_Number'] ?? '');
              }
            ?>
          </td>
          <td><?= h(!empty($v['Birthday']) ? date('m/d', strtotime($v['Birthday'])) : '') ?></td>
          <td><?= h($v['Email_Address'] ?? '') ?></td>
          <td><?= h($v['Party'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  <script>
    (function(){
      var lat = <?= json_encode($lat) ?>;
      var lon = <?= json_encode($lon) ?>;
      var r = <?= json_encode($radius_m) ?>; // meters
      try {
        var map = L.map('printMap', { zoomControl: false }).setView([lat, lon], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '' }).addTo(map);
        var circle = L.circle([lat, lon], { radius: r, color: 'red', fillOpacity: 0.2 }).addTo(map);
        try {
          var b = circle.getBounds();
          if (b && b.isValid && b.isValid()) { map.fitBounds(b.pad(0.15)); }
        } catch(e) {}
        setTimeout(function(){ try { map.invalidateSize(); } catch(_){} }, 200);
      } catch(e) {}
    })();
  </script>
</body>
</html>

