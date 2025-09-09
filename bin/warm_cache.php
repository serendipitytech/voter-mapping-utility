#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

function out($msg) { fwrite(STDOUT, $msg . "\n"); }
function err($msg) { fwrite(STDERR, $msg . "\n"); }

function get_pdo(array $config): PDO {
    $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['dbname'] . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT         => true,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, $config['username'], $config['password'], $options);
}

function census_geocode(string $address): array {
    $url = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress";
    $params = http_build_query([
        'address' => $address,
        'benchmark' => 'Public_AR_Current',
        'format' => 'json'
    ]);
    $resp = file_get_contents("$url?$params");
    if ($resp === false) return [null, null];
    $data = json_decode($resp, true);
    if (!empty($data['result']['addressMatches'])) {
        $coords = $data['result']['addressMatches'][0]['coordinates'];
        return [$coords['y'], $coords['x']];
    }
    return [null, null];
}

// Parse args
$opts = getopt('', [
    'county:',           // required
    'party::',           // optional, default ALL
    'address-ids::',     // comma-separated
    'address-id-file::', // file with one id per line
    'bbox::',            // latMin,lonMin,latMax,lonMax
    'from-address::',    // address to geocode
    'radius::',          // miles (default 0.1)
    'chunk-size::',      // default 200
    'strategy::',        // vm_in|in|derived (default vm_in)
    'respect-ttl::',     // if set, only fetch misses, else refresh
    'dry-run::',         // if set, do not write cache
]);

if (!isset($opts['county'])) {
    err("Usage: php bin/warm_cache.php --county=VOL [--party=ALL|DEM|REP|NPA] [--address-ids=1,2] [--address-id-file=ids.txt] [--bbox=latMin,lonMin,latMax,lonMax | --from-address=\"...\" --radius=0.1] [--chunk-size=200] [--strategy=vm_in|in|derived] [--respect-ttl=1] [--dry-run=1]");
    exit(1);
}

$county   = strtoupper($opts['county']);
$party    = strtoupper($opts['party'] ?? 'ALL');
$chunk    = (int)($opts['chunk-size'] ?? 200);
$strategy = $opts['strategy'] ?? 'vm_in';
$respectTtl = isset($opts['respect-ttl']);
$dryRun     = isset($opts['dry-run']);

// DB configs
$geo_db = [
    'host'     => $_ENV['GEO_DB_HOST'] ?? '127.0.0.1',
    'port'     => (int)($_ENV['GEO_DB_PORT'] ?? 3310),
    'dbname'   => $_ENV['GEO_DB_NAME'] ?? 'geocode_db',
    'username' => $_ENV['GEO_DB_USER'] ?? 'geocode',
    'password' => $_ENV['GEO_DB_PASS'] ?? 'secret',
];
$vat_db = [
    'host'     => $_ENV['VAT_DB_HOST'] ?? 'fddc-vat-prod.flddc.org',
    'port'     => (int)($_ENV['VAT_DB_PORT'] ?? 3306),
    'dbname'   => $_ENV['VAT_DB_NAME'] ?? 'sql_db',
    'username' => $_ENV['VAT_DB_USER'] ?? 'sql_read_user',
    'password' => $_ENV['VAT_DB_PASS'] ?? 'secret',
];

$geo = get_pdo($geo_db);
$vat = get_pdo($vat_db);

// Ensure cache table exists
$geo->exec("CREATE TABLE IF NOT EXISTS cached_voters (
  county CHAR(3) NOT NULL,
  address_id INT NOT NULL,
  voter_id INT NOT NULL,
  voter_name VARCHAR(100) NULL,
  first_name VARCHAR(50) NULL,
  last_name VARCHAR(50) NULL,
  email_address VARCHAR(100) NULL,
  phone_number VARCHAR(20) NULL,
  birth_date DATE NULL,
  party CHAR(10) NULL,
  voter_address TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (county, address_id, voter_id),
  INDEX idx_addr (address_id),
  INDEX idx_party (party)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Collect address_ids
$address_ids = [];
if (!empty($opts['address-ids'])) {
    foreach (explode(',', $opts['address-ids']) as $id) {
        $id = (int)trim($id);
        if ($id) $address_ids[] = $id;
    }
}
if (!empty($opts['address-id-file'])) {
    $lines = file($opts['address-id-file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $id = (int)trim($line);
        if ($id) $address_ids[] = $id;
    }
}

// From bbox or from-address
if (empty($address_ids) && !empty($opts['bbox'])) {
    [$latMin,$lonMin,$latMax,$lonMax] = array_map('floatval', explode(',', $opts['bbox']));
    $bboxWkt = sprintf(
        'POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))',
        $lonMin, $latMin, $lonMax, $latMin, $lonMax, $latMax, $lonMin, $latMax, $lonMin, $latMin
    );
    $sql = "SELECT address_id FROM geocoded_addresses WHERE MBRContains(ST_GeomFromText(?), location)";
    $st = $geo->prepare($sql);
    $st->execute([$bboxWkt]);
    $address_ids = array_map('intval', array_column($st->fetchAll(), 'address_id'));
}
if (empty($address_ids) && !empty($opts['from-address'])) {
    $radius = (float)($opts['radius'] ?? 0.1);
    [$lat,$lon] = census_geocode($opts['from-address']);
    if ($lat && $lon) {
        $latDelta = $radius / 69.0;
        $lonDelta = $radius / (max(cos(deg2rad($lat)), 0.000001) * 69.0);
        $latMin = $lat - $latDelta; $latMax = $lat + $latDelta;
        $lonMin = $lon - $lonDelta; $lonMax = $lon + $lonDelta;
        $bboxWkt = sprintf('POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))',
            $lonMin,$latMin,$lonMax,$latMin,$lonMax,$latMax,$lonMin,$latMax,$lonMin,$latMin);
        $sql = "SELECT address_id FROM geocoded_addresses WHERE MBRContains(ST_GeomFromText(?), location)
                AND (ST_Distance_Sphere(point(lon, lat), point(?, ?)) / 1609.34) <= ?";
        $st = $geo->prepare($sql);
        $st->execute([$bboxWkt, $lon, $lat, $radius]);
        $address_ids = array_map('intval', array_column($st->fetchAll(), 'address_id'));
    }
}

$address_ids = array_values(array_unique($address_ids));
if (empty($address_ids)) {
    err('No address_ids provided or found.');
    exit(1);
}

out('County: ' . $county . ', party: ' . $party . ', ids: ' . count($address_ids) . ', strategy: ' . $strategy . ', chunk: ' . $chunk);

// If respecting TTL, compute which are present and fresh
$missing_ids = $address_ids;
if ($respectTtl) {
    $ttlDays = (int)($_ENV['CACHE_TTL_DAYS'] ?? 30);
    $place = implode(',', array_fill(0, count($address_ids), '?'));
    $where = "county = ? AND address_id IN ($place) AND updated_at >= (NOW() - INTERVAL ? DAY)";
    $params = array_merge([$county], $address_ids, [$ttlDays]);
    if ($party !== 'ALL') {
        $where .= " AND party = ?";
        $params[] = $party;
    }
    $sql = "SELECT DISTINCT address_id FROM cached_voters WHERE $where";
    $st = $geo->prepare($sql);
    $st->execute($params);
    $fresh = array_map('intval', array_column($st->fetchAll(), 'address_id'));
    $missing_ids = array_values(array_diff($address_ids, $fresh));
}

out('Missing ids to fetch: ' . count($missing_ids));
if (empty($missing_ids)) { out('Nothing to do (cache fresh).'); exit(0); }

// Build VAT base
$baseWhere = "vm.county = ? AND vm.exp_date = '2100-12-31'";
$baseParams = [$county];
if ($party !== 'ALL') { $baseWhere .= " AND vm.party = ?"; $baseParams[] = $party; }

$sqlBaseVmIn = "
    SELECT /* CLI warm */
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
    JOIN demographics d ON d.id = vm.demographics_id AND d.county = vm.county
    JOIN master_voter_address mva ON vm.voter_addr_id = mva.id
    WHERE %s AND vm.voter_addr_id IN (%s)
";

$sqlBaseIn = "
    SELECT /* CLI warm */
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
    JOIN demographics d ON d.id = vm.demographics_id AND d.county = vm.county
    WHERE %s AND mva.id IN (%s)
";

$rowsAll = [];
foreach (array_chunk($missing_ids, $chunk) as $i => $chunkIds) {
    $place = implode(',', array_fill(0, count($chunkIds), '?'));
    $sql = ($strategy === 'in')
        ? sprintf($sqlBaseIn, $baseWhere, $place)
        : sprintf($sqlBaseVmIn, $baseWhere, $place);
    $params = array_merge($baseParams, $chunkIds);
    $t0 = microtime(true);
    $st = $vat->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    out(sprintf('Chunk %d: ids=%d, rows=%d, %.2f ms', $i+1, count($chunkIds), count($rows), (microtime(true)-$t0)*1000));
    $rowsAll = array_merge($rowsAll, $rows);
}

out('Total rows fetched from VAT: ' . count($rowsAll));
if ($dryRun) { out('Dry-run: not writing cache.'); exit(0); }

// Delete old and insert new
$delPlace = implode(',', array_fill(0, count($missing_ids), '?'));
$delSql = "DELETE FROM cached_voters WHERE county = ? AND address_id IN ($delPlace)";
$dst = $geo->prepare($delSql);
$dst->execute(array_merge([$county], $missing_ids));

if (!empty($rowsAll)) {
    $insRows = [];
    foreach ($rowsAll as $r) {
        $insRows[] = [
            $county,
            $r['address_id'] ?? null,
            $r['VoterID'] ?? null,
            $r['Voter_Name'] ?? null,
            $r['First_Name'] ?? null,
            $r['Last_Name'] ?? null,
            $r['Email_Address'] ?? null,
            $r['Phone_Number'] ?? null,
            isset($r['Birthday']) && $r['Birthday'] ? date('Y-m-d', strtotime($r['Birthday'])) : null,
            $r['Party'] ?? null,
            $r['Voter_Address'] ?? null,
        ];
    }
    foreach (array_chunk($insRows, 200) as $batch) {
        $place = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?,?,?)'));
        $isql = "INSERT INTO cached_voters (
            county, address_id, voter_id, voter_name, first_name, last_name,
            email_address, phone_number, birth_date, party, voter_address
        ) VALUES $place";
        $params = [];
        foreach ($batch as $row) { $params = array_merge($params, $row); }
        $ist = $geo->prepare($isql);
        $ist->execute($params);
    }
}

out('Cache warm complete.');

