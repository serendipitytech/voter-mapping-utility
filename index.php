<?php
require_once __DIR__ . '/vendor/autoload.php';

$debug = false;

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
    'dbname'   => $_ENV['VAT_DB_NAME'] ?? 'fddc_vat_sql',
    'username' => $_ENV['VAT_DB_USER'] ?? 'vat_sql_read',
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
        return [$coords['y'], $coords['x']];
    }
    return [null, null];
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

        if ($latitude && $longitude) {
            try {
                $geo_pdo = get_pdo($geo_db);
                $stmt = $geo_pdo->prepare("
                    SELECT address_id, lat, lon, full_address
                    FROM geocoded_addresses
                    WHERE (ST_Distance_Sphere(point(lon, lat), point(:lng, :lat)) / 1609.34) <= :radius
                ");
                $stmt->execute(['lat' => $latitude, 'lng' => $longitude, 'radius' => $radius]);
                $nearby = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $address_ids = array_column($nearby, 'address_id');

                if (!empty($address_ids)) {
                    $placeholders = implode(',', array_fill(0, count($address_ids), '?'));
                    $where = "vm.county = ? AND vm.exp_date = '2100-12-31'";
                    $params = [$county];

                    if ($party !== 'ALL') {
                        $where .= " AND d.party = ?";
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
                    sort_voters_by_address($voters);
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
<body onload="initMap()">
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
                        $counties = ['ALA' => 'Alachua', 'BRE' => 'Brevard', 'BRO' => 'Broward', 'VOL' => 'Volusia', 'DAD' => 'Miami-Dade']; // expand as needed
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
                            <input type="text" class="form-control" name="address" id="address" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '1726 Grand Ave, Deland, FL 32720'; ?>" required>
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
        <button onclick="window.print();" class="btn btn-primary d-inline-block mb-3">Print Page</button>

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
                    <tr>
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

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const lat = <?php echo $latitude ?? 29.0283; ?>;
    const lon = <?php echo $longitude ?? -81.3031; ?>;
    const radiusInMeters = <?php echo isset($radius) ? $radius * 1609.34 : 1609.34; ?>;

    const map = L.map('map').setView([lat, lon], 14);

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Search marker
    const searchMarker = L.marker([lat, lon]).addTo(map).bindPopup("Search Address").openPopup();

    // Radius circle
    L.circle([lat, lon], {
        radius: radiusInMeters,
        color: 'red',
        fillOpacity: 0.2
    }).addTo(map);

    // Add voter markers
    <?php foreach ($voters as $voter): ?>
        L.marker([<?php echo $voter['Latitude']; ?>, <?php echo $voter['Longitude']; ?>])
          .addTo(map)
          .bindPopup(`<?php echo addslashes($voter['Voter_Name'] . "<br>" . $voter['Voter_Address']); ?>`);
    <?php endforeach; ?>
});
</script>
<script>
document.querySelector("form").addEventListener("submit", function () {
    document.getElementById("searchingIndicator").classList.remove("d-none");
});
</script>
</body>
</html>