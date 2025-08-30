<?php
require_once __DIR__ . '/vendor/autoload.php';

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
$debug_log = [];

function debug_log($title, $variant, $content) {
    global $debug, $debug_log;
    if ($debug) {
        $debug_log[] = [
            'title' => $title,
            'variant' => $variant,
            'content' => is_array($content) ? print_r($content, true) : $content
        ];
    }
}
// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// DB config
$geo_db = [
    'host'     => $_ENV['GEO_DB_HOST'] ?? '127.0.0.1',
    'port'     => $_ENV['GEO_DB_PORT'] ?? 3310,
    'dbname'   => $_ENV['GEO_DB_NAME'] ?? 'geocode_db',
    'username' => $_ENV['GEO_DB_USER'] ?? 'geocode',
    'password' => $_ENV['GEO_DB_PASS'] ?? 'secret'
];

$vat_db = [
    'host'     => $_ENV['VAT_DB_HOST'] ?? 'fddc-vat-prod.flddc.org',
    'port'     => $_ENV['VAT_DB_PORT'] ?? 3306,
    'dbname'   => $_ENV['VAT_DB_NAME'] ?? 'sql_db',
    'username' => $_ENV['VAT_DB_USER'] ?? 'sql_read_user',
    'password' => $_ENV['VAT_DB_PASS'] ?? 'secret'
];

// === Helpers ===
function get_pdo($config) {
    $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['dbname'];
    $pdo = new PDO($dsn, $config['username'], $config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function sort_voters_by_address(&$voters) {
    usort($voters, function ($a, $b) {
        $cmp = strcmp($a['Voter_Address'], $b['Voter_Address']);
        return $cmp !== 0 ? $cmp : strcmp($a['Last_Name'], $b['Last_Name']);
    });
}

function census_geocode($address) {
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/geocode_cache.json';
    static $cache = null;

    if ($cache === null) {
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cache = file_exists($cacheFile)
            ? json_decode(file_get_contents($cacheFile), true)
            : [];
    }

    if (isset($cache[$address])) {
        return $cache[$address];
    }

    $url = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress";
    $params = http_build_query([
        'address' => $address,
        'benchmark' => 'Public_AR_Current',
        'format' => 'json'
    ]);
    $response = file_get_contents("$url?$params");
    $data = json_decode($response, true);
    if (!empty($data['result']['addressMatches'])) {
        $coords = $data['result']['addressMatches'][0]['coordinates'];
        $cache[$address] = [$coords['y'], $coords['x']];
        file_put_contents($cacheFile, json_encode($cache));
        return $cache[$address];
    }
    return [null, null];
}
function sort_voters_by_distance_and_street(&$voters, $origin_lat, $origin_lon) {
    usort($voters, function ($a, $b) use ($origin_lat, $origin_lon) {
        // Compute distance to origin using Haversine
        $distA = haversine_distance($origin_lat, $origin_lon, $a['Latitude'], $a['Longitude']);
        $distB = haversine_distance($origin_lat, $origin_lon, $b['Latitude'], $b['Longitude']);

        // If distances are different, sort by distance
        if ($distA != $distB) {
            return $distA <=> $distB;
        }

        // Strip house number to sort by street name
        $streetA = preg_replace('/^\d+\s*/', '', $a['Voter_Address']);
        $streetB = preg_replace('/^\d+\s*/', '', $b['Voter_Address']);

        return strcmp($streetA, $streetB);
    });
}

function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // km

    $latFrom = deg2rad($lat1);
    $lonFrom = deg2rad($lon1);
    $latTo = deg2rad($lat2);
    $lonTo = deg2rad($lon2);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $a = sin($latDelta / 2) ** 2 +
         cos($latFrom) * cos($latTo) *
         sin($lonDelta / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c; // distance in km
}

// === Input ===
$error = '';
$latitude = null;
$longitude = null;
$voters = [];

$counties = ['ALA', 'BRE', 'BRO', 'VOL', 'DAD'];
$parties  = ['ALL', 'DEM', 'REP', 'NPA'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $address = trim($_POST['address'] ?? '');
    $radius  = floatval($_POST['radius'] ?? 0);
    $county  = strtoupper(trim($_POST['county'] ?? ''));
    $party   = strtoupper(trim($_POST['party'] ?? 'ALL'));

    if (empty($address)) {
        $error = "Please enter an address.";
    } elseif ($radius <= 0) {
        $error = "Please enter a valid radius greater than 0.";
    } elseif (!in_array($county, $counties)) {
        $error = "Invalid county.";
    } elseif (!in_array($party, $parties)) {
        $error = "Invalid party.";
    } else {
        list($latitude, $longitude) = census_geocode($address);
        debug_log('Geocoding', 'info', "Address: $address, Latitude: $latitude, Longitude: $longitude");
        if ($latitude && $longitude) {
            try {
                $geo_pdo = get_pdo($geo_db);

                $latDelta = $radius / 69.0;
                $lonDelta = $radius / (cos(deg2rad($latitude)) * 69.0);
                $latMin = $latitude - $latDelta;
                $latMax = $latitude + $latDelta;
                $lonMin = $longitude - $lonDelta;
                $lonMax = $longitude + $lonDelta;

                $raw_sql = "
                    SELECT address_id, lat, lon, full_address
                    FROM geocoded_addresses
                    WHERE lat BETWEEN :lat_min AND :lat_max
                      AND lon BETWEEN :lon_min AND :lon_max
                      AND (ST_Distance_Sphere(point(lon, lat), point(:lng, :lat)) / 1609.34) <= :radius
                ";

                $params_debug = [
                    ':lat'     => $latitude,
                    ':lng'     => $longitude,
                    ':radius'  => $radius,
                    ':lat_min' => $latMin,
                    ':lat_max' => $latMax,
                    ':lon_min' => $lonMin,
                    ':lon_max' => $lonMax
                ];

                $debug_sql = $raw_sql;
                foreach ($params_debug as $key => $value) {
                    $debug_sql = str_replace($key, is_numeric($value) ? $value : "'$value'", $debug_sql);
                }

                debug_log('Interpolated Geo SQL', 'info', $debug_sql);
                debug_log('Geo Query Params', 'secondary', $params_debug);

                $stmt = $geo_pdo->prepare($raw_sql);
                $stmt->execute([
                    'lat'     => $latitude,
                    'lng'     => $longitude,
                    'radius'  => $radius,
                    'lat_min' => $latMin,
                    'lat_max' => $latMax,
                    'lon_min' => $lonMin,
                    'lon_max' => $lonMax
                ]);

                $nearby = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $address_ids = array_column($nearby, 'address_id');

                // Log raw data
                debug_log('Nearby Addresses', 'info', $nearby);
                debug_log('Address IDs', 'info', $address_ids);
                
                if (!empty($address_ids)) {
                    $placeholders = implode(',', array_fill(0, count($address_ids), '?'));
                    $where = "vm.county = ? AND vm.exp_date = '2100-12-31'";
                    $params = [$county];

                    if ($party !== 'ALL') {
                        $where .= " AND vm.party = ?";
                        $params[] = $party;
                    }

                    $where .= " AND mva.id IN ($placeholders)";
                    $params = array_merge($params, $address_ids);

                    $vat_pdo = get_pdo($vat_db);
                    $sql = "
                        SELECT
                            vm.voter_id AS VoterID,
                            CONCAT_WS(CHAR(10), mva.street_address, TRIM(CONCAT_WS(' ', mva.address_line2, mva.apt_number))) AS Voter_Address,
                            d.voter_name AS Voter_Name,
                            d.last_name AS Last_Name,
                            d.first_name AS First_Name,
                            d.email_address AS Email_Address,
                            d.birth_date AS Birthday,
                            d.phone_number AS Phone_Number,
                            vm.party AS Party,
                            mva.id AS address_id
                        FROM voter_master vm
                        JOIN master_voter_address mva ON vm.voter_addr_id = mva.id
                        JOIN demographics d ON d.voter_id = vm.voter_id
                        WHERE $where
                    ";
                    debug_log('SQL Query', 'info', $sql);
                    debug_log('SQL Params', 'info', $params);
                    $stmt = $vat_pdo->prepare($sql);
                    $stmt->execute($params);
                    $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $latlon_lookup = [];
                    foreach ($nearby as $row) {
                        $latlon_lookup[$row['address_id']] = ['lat' => $row['lat'], 'lon' => $row['lon']];
                    }

                    foreach ($voters as &$voter) {
                        $id = $voter['address_id'];
                        if (isset($latlon_lookup[$id])) {
                            $voter['Latitude'] = $latlon_lookup[$id]['lat'];
                            $voter['Longitude'] = $latlon_lookup[$id]['lon'];
                        }
                    }
                    unset($voter);
                    sort_voters_by_distance_and_street($voters, $latitude, $longitude);
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Geocoding failed: Address not recognized by Census API.";
        }
    }
}

$display_fields = [
    'VoterID'        => 'Voter ID',
    'Last_Name'     => 'Last Name',
    'First_Name'     => 'First Name',
    'Voter_Address'  => 'Address',
    'Phone_Number'   => 'Phone',
    'Birthday'       => 'Birthday',
    'Email_Address'  => 'Email',
    'Party'          => 'Party',

];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Voters Within Radius</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <link rel="stylesheet" href="styles.css">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

        <style>
            #map {
                height: 500px;
                width: 100%;
                margin-top: 20px;
                border: 1px solid #ccc;
            }

@media print {
    @page {
        size: landscape;
        margin: 0.25in;
    }

    body {
        margin: 0.25in;
        font-size: 10pt;
    }

    .container,
    .table-container,
    table {
        width: 100% !important;
        max-width: 100%;
    }

    table {
        border-collapse: collapse;
        table-layout: fixed;
    }

    th, td {
        border: 1px solid #ccc;
        padding: 4px 6px;
        text-align: left;
        vertical-align: top;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    th:nth-child(1), td:nth-child(1) { width: 100px; }   /* Voter ID */
    th:nth-child(2), td:nth-child(2) { width: 200px; }   /* Name */
    th:nth-child(3), td:nth-child(3) { width: 180px; }   /* Address */
    th:nth-child(4), td:nth-child(4) { width: 100px; }   /* Phone */
    th:nth-child(5), td:nth-child(5) { width: 80px; }    /* DOB */
    th:nth-child(6), td:nth-child(6) { width: 180px; }   /* Email */
    th:nth-child(7), td:nth-child(7) { width: 60px; }    /* Party */
    th:nth-child(8), td:nth-child(8) {
        width: 160px;
        white-space: pre-wrap;
    }  /* Notes */

    textarea,
    .form-container, form, .btn,
    button {
        display: none !important;
    }

    #map {
        height: 300px !important;
        margin-bottom: 20px;
        display: block !important;
    }

    h3 {
        margin-top: 10px;
    }

    .notes-column {
        display: table-cell !important;
    }
}

@media screen {
    .notes-column {
        display: none;
    }
}
</style>


</head>
<body>
    <div class="container mt-4">
        <h2 class="text-center mb-4">Find Voters Within a Radius</h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="form-container p-3">
                    <form method="POST">
                        <?php
                        //$counties = ['ALA' => 'Alachua', 'BRE' => 'Brevard', 'BRO' => 'Broward', 'VOL' => 'Volusia', 'DAD' => 'Miami-Dade']; // expand as needed
                        $counties = ['VOL' => 'Volusia', 'LEE' => 'Lee'];
                        $selected_county = $_POST['county'] ?? 'VOL';
                        ?>
                        <div class="mb-3">
                            <label for="county" class="form-label">Select County:</label>
                            <select class="form-select" name="county" id="county" required>
                                <?php foreach ($counties as $code => $name): ?>
                                    <option value="<?= htmlspecialchars($code) ?>" <?= $code === $selected_county ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php
                            $party_options = ['ALL' => 'All Parties', 'DEM' => 'Democrats', 'REP' => 'Republicans', 'NPA' => 'No Party Affiliation'];
                            $selected_party = $_POST['party'] ?? 'ALL';
                            ?>
                            <div class="mb-3">
                                <label for="party" class="form-label">Select Party:</label>
                                <select class="form-select" name="party" id="party" required>
                                    <?php foreach ($party_options as $code => $label): ?>
                                        <option value="<?= $code ?>" <?= $code === $selected_party ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Enter Address:</label>
                            <input type="text" class="form-control" name="address" id="address" list="addressCache" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '1726 Grand Ave, Deland, FL 32720'; ?>" required>
                            <datalist id="addressCache"></datalist>
                        </div>

                        <div class="mb-3">
                            <label for="radius" class="form-label">Enter Radius (miles):</label>
                            <input type="number" class="form-control" step="0.01" name="radius" id="radius" value="<?php echo isset($_POST['radius']) ? htmlspecialchars($_POST['radius']) : '.1'; ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                    <p>When entering a radius, start with .1 and slowly increase this value 1/10th of a mile at a time to avoid overloading the results and to make sure you have a manageable list. You can also go smaller if needed.</p>
                </div>
                              <div id="searchingIndicator" class="alert alert-info d-none mt-3">
                <div class="spinner-border spinner-border-sm mr-2" role="status"></div>
                <strong>Searching...</strong> Please wait while we retrieve nearby voters.
            </div> 
            </div>
                             
            <div class="col-md-8">
                <div id="map"></div>
            </div>
        </div>

<?php if (!empty($voters)): ?>
    <div class="table-container mt-4">
        <h3 class="mb-3">Voters Within <?php echo $radius; ?> Miles of <?php echo htmlspecialchars($address); ?></h3>
        <div class="d-flex justify-content-between mb-3">
            <button onclick="window.print();" class="btn btn-primary">Print Page</button>
            <div>
                <label for="sortOption" class="me-2">Sort by:</label>
                <select id="sortOption" class="form-select d-inline-block w-auto">
                    <option value="path">Optimal Route</option>
                    <option value="street">Street</option>
                </select>
            </div>
        </div>

        <table class="table table-striped table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>Voter ID</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Phone</th>
                    <th>DOB</th>
                    <th>Email</th>
                    <th>Party</th>
                    <th class="notes-column">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($voters as $voter): ?>
                    <tr data-voter-id="<?= htmlspecialchars($voter['VoterID'] ?? '') ?>">
                        <td><?= htmlspecialchars($voter['VoterID'] ?? '') ?></td>
                        <td><?= htmlspecialchars(($voter['Last_Name'] ?? '') . ', ' . ($voter['First_Name'] ?? '')) ?></td>
                        <td><?= nl2br(htmlspecialchars($voter['Voter_Address'] ?? '')) ?></td>
                        <td>
                            <?php
                                $phone = preg_replace('/[^0-9]/', '', $voter['Phone_Number'] ?? '');
                                if (strlen($phone) === 10) {
                                    echo "(" . substr($phone, 0, 3) . ") " . substr($phone, 3, 3) . "-" . substr($phone, 6);
                                } else {
                                    echo htmlspecialchars($voter['Phone_Number'] ?? '');
                                }
                            ?>
                        </td>
                        <td><?= htmlspecialchars(date('m/d', strtotime($voter['Birthday'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars($voter['Email_Address'] ?? '') ?></td>
                        <td><?= htmlspecialchars($voter['Party'] ?? '') ?></td>
                        <td class="notes-column">&nbsp;</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
    </div>
<?php if (!empty($debug_log)): ?>
<div class="container mt-4">
    <?php foreach ($debug_log as $debug): ?>
        <div class="card border-<?= $debug['variant'] ?> mb-3">
            <div class="card-header bg-<?= $debug['variant'] ?> text-white fw-bold">
                Debug: <?= htmlspecialchars($debug['title']) ?>
            </div>
            <div class="card-body">
                <pre class="mb-0"><?= htmlspecialchars($debug['content']) ?></pre>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const voters = <?php echo json_encode($voters); ?>;

    function haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const toRad = deg => deg * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2 +
                  Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                  Math.sin(dLon / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function computeOptimalRoute(points) {
        if (!points || points.length === 0) return [];
        const n = points.length;
        const dist = Array.from({ length: n }, () => Array(n).fill(0));
        for (let i = 0; i < n; i++) {
            for (let j = i + 1; j < n; j++) {
                const d = haversineDistance(points[i].Latitude, points[i].Longitude,
                                           points[j].Latitude, points[j].Longitude);
                dist[i][j] = dist[j][i] = d;
            }
        }

        const route = [0];
        const visited = new Array(n).fill(false);
        visited[0] = true;
        for (let i = 1; i < n; i++) {
            const last = route[route.length - 1];
            let nearest = -1;
            for (let j = 0; j < n; j++) {
                if (!visited[j] && (nearest === -1 || dist[last][j] < dist[last][nearest])) {
                    nearest = j;
                }
            }
            route.push(nearest);
            visited[nearest] = true;
        }

        let improved = true;
        while (improved) {
            improved = false;
            for (let i = 1; i < n - 1; i++) {
                for (let k = i + 1; k < n; k++) {
                    const a = route[i - 1], b = route[i], c = route[k - 1], d = route[k];
                    const current = dist[a][b] + dist[c][d];
                    const swapped = dist[a][c] + dist[b][d];
                    if (swapped < current) {
                        route.splice(i, k - i, ...route.slice(i, k).reverse());
                        improved = true;
                    }
                }
            }
        }
        return route.map(idx => points[idx]);
    }

    function sortByStreet(points) {
        return points.slice().sort((a, b) => {
            const addrA = (a.Voter_Address || '').split('\n')[0];
            const addrB = (b.Voter_Address || '').split('\n')[0];
            const streetA = addrA.replace(/^\d+\s*/, '').toLowerCase();
            const streetB = addrB.replace(/^\d+\s*/, '').toLowerCase();
            if (streetA !== streetB) return streetA.localeCompare(streetB);
            const numA = parseInt(addrA) || 0;
            const numB = parseInt(addrB) || 0;
            return numA - numB;
        });
    }
    </script>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const lat = <?php echo $latitude ?? 29.0283; ?>;
        const lon = <?php echo $longitude ?? -81.3031; ?>;
        const radiusInMeters = <?php echo isset($radius) ? $radius * 1609.34 : 1609.34; ?>;

        const map = L.map('map').setView([lat, lon], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        L.marker([lat, lon]).addTo(map).bindPopup("Search Address").openPopup();
        L.circle([lat, lon], {
            radius: radiusInMeters,
            color: 'red',
            fillOpacity: 0.2
        }).addTo(map);

        const optimized = computeOptimalRoute(voters);
        const streetSorted = sortByStreet(voters);
        const markerLayer = L.layerGroup().addTo(map);
        let routeLine = null;

        function render(list) {
            const tbody = document.querySelector('table tbody');
            if (tbody) {
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const rowMap = {};
                rows.forEach(r => rowMap[r.dataset.voterId] = r);
                tbody.innerHTML = '';
                list.forEach(v => {
                    const row = rowMap[v.VoterID];
                    if (row) tbody.appendChild(row);
                });
            }

            markerLayer.clearLayers();
            if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
            const path = [];
            list.forEach(v => {
                path.push([v.Latitude, v.Longitude]);
                markerLayer.addLayer(
                    L.marker([v.Latitude, v.Longitude]).bindPopup(`${v.Voter_Name}<br>${v.Voter_Address}`)
                );
            });
            if (path.length) {
                routeLine = L.polyline(path, { color: 'blue' }).addTo(map);
            }
        }

        const sortSel = document.getElementById('sortOption');
        if (sortSel) {
            sortSel.addEventListener('change', function () {
                render(this.value === 'street' ? streetSorted : optimized);
            });
        }

        render(optimized);

        fetch('addresses.json')
            .then(r => r.json())
            .then(list => {
                const dl = document.getElementById('addressCache');
                list.forEach(addr => {
                    const opt = document.createElement('option');
                    opt.value = addr;
                    dl.appendChild(opt);
                });
            })
            .catch(() => {});
    });
    </script>
<script>
document.querySelector("form").addEventListener("submit", function () {
    document.getElementById("searchingIndicator").classList.remove("d-none");
});
</script>
</body>
</html>
