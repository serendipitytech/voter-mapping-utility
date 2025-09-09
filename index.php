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

// Cache TTL (days); override via .env CACHE_TTL_DAYS=7
$cache_ttl_days = intval($_ENV['CACHE_TTL_DAYS'] ?? 30);

if ($debug) {
    debug_log('DB Config (hosts)', 'info', [
        'geo' => [
            'host' => $geo_db['host'], 'port' => $geo_db['port'], 'db' => $geo_db['dbname'], 'user' => $geo_db['username']
        ],
        'vat' => [
            'host' => $vat_db['host'], 'port' => $vat_db['port'], 'db' => $vat_db['dbname'], 'user' => $vat_db['username']
        ]
    ]);
}

// === Helpers ===
function get_pdo($config) {
    $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['dbname'] . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT         => true,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, $config['username'], $config['password'], $options);
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
    $t0 = microtime(true);
    $response = file_get_contents("$url?$params");
    $data = json_decode($response, true);
    if (!empty($data['result']['addressMatches'])) {
        $coords = $data['result']['addressMatches'][0]['coordinates'];
        $cache[$address] = [$coords['y'], $coords['x']];
        file_put_contents($cacheFile, json_encode($cache));
        debug_log('Geocode time', 'secondary', sprintf('%.2f ms', (microtime(true) - $t0) * 1000));
        return $cache[$address];
    }
    debug_log('Geocode time (no match)', 'warning', sprintf('%.2f ms', (microtime(true) - $t0) * 1000));
    return [null, null];
}

function sort_voters_by_distance_and_street(&$voters, $origin_lat, $origin_lon) {
    usort($voters, function ($a, $b) use ($origin_lat, $origin_lon) {
        $distA = haversine_distance($origin_lat, $origin_lon, $a['Latitude'], $a['Longitude']);
        $distB = haversine_distance($origin_lat, $origin_lon, $b['Latitude'], $b['Longitude']);

        if ($distA != $distB) {
            return $distA <=> $distB;
        }

        $streetA = preg_replace('/^\d+\s*/', '', $a['Voter_Address']);
        $streetB = preg_replace('/^\d+\s*/', '', $b['Voter_Address']);

        return strcmp($streetA, $streetB);
    });
}

function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $latFrom = deg2rad($lat1);
    $lonFrom = deg2rad($lon1);
    $latTo   = deg2rad($lat2);
    $lonTo   = deg2rad($lon2);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $a = sin($latDelta / 2) ** 2 +
         cos($latFrom) * cos($latTo) *
         sin($lonDelta / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

// === GeoIP helpers ===
function is_public_ip($ip) {
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function get_client_ip() {
    $keys = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ips = explode(',', $_SERVER[$k]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}

function http_get_json($url, $timeout = 1.5) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: voter-mapping-utility\r\n",
        ]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

function map_county_name_to_code($name) {
    // Map FL county names to 3-letter codes used by VAT
    static $map = null;
    if ($map === null) {
        $pairs = [
            'ALACHUA' => 'ALA','BAKER' => 'BAK','BAY' => 'BAY','BRADFORD' => 'BRA','BREVARD' => 'BRE','BROWARD' => 'BRO','CALHOUN' => 'CAL','CHARLOTTE' => 'CHA','CITRUS' => 'CIT','CLAY' => 'CLA','COLLIER' => 'CLL','COLUMBIA' => 'CLM','MIAMI-DADE' => 'DAD','DESOTO' => 'DES','DIXIE' => 'DIX','DUVAL' => 'DUV','ESCAMBIA' => 'ESC','FLAGLER' => 'FLA','FRANKLIN' => 'FRA','GADSDEN' => 'GAD','GILCHRIST' => 'GIL','GLADES' => 'GLA','GULF' => 'GUL','HAMILTON' => 'HAM','HARDEE' => 'HAR','HENDRY' => 'HEN','HERNANDO' => 'HER','HIGHLANDS' => 'HIG','HILLSBOROUGH' => 'HIL','HOLMES' => 'HOL','INDIAN RIVER' => 'IND','JACKSON' => 'JAC','JEFFERSON' => 'JEF','LAFAYETTE' => 'LAF','LAKE' => 'LAK','LEE' => 'LEE','LEON' => 'LEO','LEVY' => 'LEV','LIBERTY' => 'LIB','MADISON' => 'MAD','MANATEE' => 'MAN','MARION' => 'MRN','MARTIN' => 'MRT','MONROE' => 'MON','NASSAU' => 'NAS','OKALOOSA' => 'OKA','OKEECHOBEE' => 'OKE','ORANGE' => 'ORA','OSCEOLA' => 'OSC','PALM BEACH' => 'PAL','PASCO' => 'PAS','PINELLAS' => 'PIN','POLK' => 'POL','PUTNAM' => 'PUT','SANTA ROSA' => 'SAN','SARASOTA' => 'SAR','SEMINOLE' => 'SEM','ST. JOHNS' => 'STJ','ST. LUCIE' => 'STL','SUMTER' => 'SUM','SUWANNEE' => 'SUW','TAYLOR' => 'TAY','UNION' => 'UNI','VOLUSIA' => 'VOL','WAKULLA' => 'WAK','WALTON' => 'WAL','WASHINGTON' => 'WAS'
        ];
        $map = [];
        foreach ($pairs as $label => $code) {
            $map[$label] = $code;
            $map[$label . ' COUNTY'] = $code; // FCC returns with " County"
        }
    }
    $key = strtoupper(trim($name));
    return $map[$key] ?? null;
}

function geoip_detect(&$geo_lat, &$geo_lon, &$geo_county_code) {
    $enabled = ($_ENV['GEOIP_ENABLED'] ?? '1') !== '0';
    if (!$enabled) return;
    $ip = get_client_ip();
    if (!$ip) return;
    // 1) Rough lat/lon via ip-api.com
    // Use public IPs only; skip private ranges (common in local Docker)
    if (!is_public_ip($ip)) return;
    $j = http_get_json('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,lat,lon,country,region,city');
    if (is_array($j) && ($j['status'] ?? '') === 'success') {
        $geo_lat = $j['lat'] ?? $geo_lat;
        $geo_lon = $j['lon'] ?? $geo_lon;
        // 2) County via FCC API using lat/lon
        if ($geo_lat && $geo_lon) {
            $fcc = http_get_json('https://geo.fcc.gov/api/census/area?format=json&lat=' . urlencode((string)$geo_lat) . '&lon=' . urlencode((string)$geo_lon));
            if (is_array($fcc) && !empty($fcc['results'][0]['county_name'])) {
                $geo_county_code = map_county_name_to_code($fcc['results'][0]['county_name']);
            }
        }
    }
}

// === Input ===
$error = '';
$latitude = null;
$longitude = null;
$voters = [];

$counties = [];
$available_counties = [];
try {
    $tmp_pdo = get_pdo($geo_db);
    $rs = $tmp_pdo->query("SELECT DISTINCT county FROM geocoded_addresses WHERE county IS NOT NULL AND county <> '' ORDER BY county");
    $available_counties = $rs ? $rs->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!empty($available_counties)) { $counties = $available_counties; }
} catch (Exception $e) {
    debug_log('County load warning', 'warning', $e->getMessage());
}
// Fallback full list if DB does not have county column or no data yet
if (empty($counties)) {
    $counties = ['ALA','BAK','BAY','BRA','BRE','BRO','CAL','CHA','CIT','CLA','CLL','CLM','DAD','DES','DIX','DUV','ESC','FLA','FRA','GAD','GIL','GLA','GUL','HAM','HAR','HEN','HER','HIG','HIL','HOL','IND','JAC','JEF','LAF','LAK','LEE','LEO','LEV','LIB','MAD','MAN','MRN','MRT','MON','NAS','OKA','OKE','ORA','OSC','PAL','PAS','PIN','POL','PUT','SAN','SAR','SEM','STJ','STL','SUM','SUW','TAY','UNI','VOL','WAK','WAL','WAS'];
}
$parties  = ['ALL', 'DEM', 'REP', 'NPA'];

// Attempt to geolocate user on initial load (GET), to set map default and county
$geoip_county = null;
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $g_lat = null; $g_lon = null; $g_code = null;
    geoip_detect($g_lat, $g_lon, $g_code);
    if ($g_lat && $g_lon) {
        $latitude = $latitude ?? $g_lat;
        $longitude = $longitude ?? $g_lon;
    }
    if ($g_code && in_array($g_code, $counties, true)) {
        $geoip_county = $g_code;
    }
    // Fallback to env-configured defaults if GeoIP is unavailable (common on local dev)
    if (!$geoip_county && !empty($_ENV['DEFAULT_COUNTY']) && in_array($_ENV['DEFAULT_COUNTY'], $counties, true)) {
        $geoip_county = $_ENV['DEFAULT_COUNTY'];
    }
    if ((!isset($latitude) || !isset($longitude)) && !empty($_ENV['DEFAULT_CENTER_LAT']) && !empty($_ENV['DEFAULT_CENTER_LON'])) {
        $latitude = (float) $_ENV['DEFAULT_CENTER_LAT'];
        $longitude = (float) $_ENV['DEFAULT_CENTER_LON'];
    }
}

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
        $t_all = microtime(true);
        list($latitude, $longitude) = census_geocode($address);
        debug_log('Geocoding', 'info', "Address: $address, Latitude: $latitude, Longitude: $longitude");
        if ($latitude && $longitude) {
                $geo_pdo = get_pdo($geo_db);

                // Compute bounding box in degrees for an MBR prefilter
                $latDelta = $radius / 69.0; // ~69 miles per degree latitude
                $lonDelta = $radius / (max(cos(deg2rad($latitude)), 0.000001) * 69.0);
                $latMin = $latitude - $latDelta;
                $latMax = $latitude + $latDelta;
                $lonMin = $longitude - $lonDelta;
                $lonMax = $longitude + $lonDelta;

                // Use spatial index on `location` with MBRContains for fast prefilter,
                // then refine using precise spherical distance (miles)
                $bboxWkt = sprintf(
                    'POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))',
                    $lonMin, $latMin,
                    $lonMax, $latMin,
                    $lonMax, $latMax,
                    $lonMin, $latMax,
                    $lonMin, $latMin
                );

                $raw_sql = "
                    SELECT address_id, lat, lon, full_address
                    FROM geocoded_addresses
                    WHERE MBRContains(ST_GeomFromText(:bbox_wkt), location)
                      AND (ST_Distance_Sphere(point(lon, lat), point(:lng, :lat)) / 1609.34) <= :radius
                ";
                $geo_params = [
                    'lat'      => $latitude,
                    'lng'      => $longitude,
                    'radius'   => $radius,
                    'bbox_wkt' => $bboxWkt,
                ];
                if ($debug) {
                    $dbg_sql = $raw_sql;
                    foreach ($geo_params as $k => $v) {
                        $dbg_sql = str_replace(':' . $k, is_numeric($v) ? (string)$v : "'" . $v . "'", $dbg_sql);
                    }
                    debug_log('Geo SQL', 'secondary', $dbg_sql);
                }
                $t_geo_q = microtime(true);
                $stmt = $geo_pdo->prepare($raw_sql);
                $stmt->execute($geo_params);

                $nearby = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $address_ids = array_column($nearby, 'address_id');
                debug_log('Geo query timing', 'info', [
                    'rows' => count($nearby),
                    'duration_ms' => round((microtime(true) - $t_geo_q) * 1000, 2),
                    'bbox' => ['latMin' => $latMin, 'latMax' => $latMax, 'lonMin' => $lonMin, 'lonMax' => $lonMax]
                ]);

                if (!empty($address_ids)) {
                    // Ensure cache table exists
                    try {
                        $geo_pdo->exec("CREATE TABLE IF NOT EXISTS cached_voters (
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
                    } catch (Exception $e) {
                        debug_log('Cache table error', 'warning', $e->getMessage());
                    }

                    // Load any cached voters for these addresses (respect TTL)
                    $voters = [];
                    $cached_address_ids = [];
                    $useCache = empty($_GET['refresh']);
                    if ($useCache) {
                        $cachePlace = implode(',', array_fill(0, count($address_ids), '?'));
                        $cacheWhere = "county = ? AND address_id IN ($cachePlace) AND updated_at >= (NOW() - INTERVAL ? DAY)";
                        $cacheParams = array_merge([$county], $address_ids, [$cache_ttl_days]);
                        if ($party !== 'ALL') {
                            $cacheWhere .= " AND party = ?";
                            $cacheParams[] = $party;
                        }
                        $cacheSql = "
                            SELECT
                                voter_id   AS VoterID,
                                voter_address AS Voter_Address,
                                voter_name AS Voter_Name,
                                last_name  AS Last_Name,
                                first_name AS First_Name,
                                email_address AS Email_Address,
                                birth_date AS Birthday,
                                phone_number AS Phone_Number,
                                party AS Party,
                                address_id
                            FROM cached_voters
                            WHERE $cacheWhere
                        ";
                        try {
                            $cstmt = $geo_pdo->prepare($cacheSql);
                            $cstmt->execute($cacheParams);
                            $cachedRows = $cstmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($cachedRows as $cr) {
                                $voters[] = $cr;
                                $cached_address_ids[$cr['address_id']] = true;
                            }
                        } catch (Exception $e) {
                            debug_log('Cache read error', 'warning', $e->getMessage());
                        }
                    }

                    // Determine which address_ids are missing from cache
                    $missing_ids = $useCache
                        ? array_values(array_diff($address_ids, array_keys($cached_address_ids)))
                        : $address_ids;
                    debug_log('Cache status', 'info', [
                        'cached_rows' => isset($cachedRows) ? count($cachedRows) : 0,
                        'missing_ids' => count($missing_ids)
                    ]);

                    // If all results are cached, skip VAT
                    if (empty($missing_ids)) {
                        // continue to attach lat/lon below
                    } else {
                    $vat_pdo = get_pdo($vat_db);
                    // Try to discourage hash join which causes full scan of demographics
                    try { $vat_pdo->exec("SET SESSION optimizer_switch='hash_join=off'"); } catch (Exception $e) { /* ignore */ }
                    $strategy = isset($_GET['vat_strategy']) ? $_GET['vat_strategy'] : 'vm_in'; // default to fastest observed
                    $chunkSize = ($debug ? 50 : 200);
                    $baseWhere = "vm.county = ? AND vm.exp_date = '2100-12-31'";
                    $baseParams = [$county];
                    if ($party !== 'ALL') {
                        $baseWhere .= " AND vm.party = ?";
                        $baseParams[] = $party;
                    }
                    
                    $sqlBaseDerived = "
                        SELECT /*+ NO_HASH_JOIN */ STRAIGHT_JOIN
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
                        FROM (%s) AS ids
                        JOIN master_voter_address mva ON mva.id = ids.id
                        JOIN voter_master vm ON vm.voter_addr_id = mva.id
                        JOIN demographics d ON d.id = vm.demographics_id AND d.county = vm.county
                        WHERE %s
                    ";

                    $sqlBaseIn = "
                        SELECT /*+ NO_HASH_JOIN */
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

                    $sqlBaseVmIn = "
                        SELECT /*+ NO_HASH_JOIN */
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

                    $chunks = array_chunk($missing_ids, $chunkSize);
                    $t_vat_all = microtime(true);
                    if ($strategy === 'two_step') {
                        // Single-step two_step: include demographics via PK join to avoid full scan
                        foreach ($chunks as $i => $chunk) {
                            $values = implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS id'));
                            $sql = sprintf(
                                "SELECT /*+ NO_HASH_JOIN */ STRAIGHT_JOIN
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
                                 FROM (%s) AS ids
                                 JOIN master_voter_address mva ON mva.id = ids.id
                                 JOIN voter_master vm ON vm.voter_addr_id = mva.id
                                 JOIN demographics d ON d.id = vm.demographics_id AND d.county = vm.county
                                 WHERE %s",
                                $values,
                                $baseWhere
                            );
                            $params = array_merge($chunk, $baseParams);
                            $t_chunk = microtime(true);
                            $stmt = $vat_pdo->prepare($sql);
                            $stmt->execute($params);
                            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            $voters = array_merge($voters, $rows);
                            if ($debug) {
                                debug_log('two_step (single) chunk ' . ($i+1), 'info', [
                                    'ids' => count($chunk),
                                    'rows' => count($rows),
                                    'duration_ms' => round((microtime(true) - $t_chunk) * 1000, 2)
                                ]);
                            }
                        }
                        debug_log('VAT two_step total', 'info', [
                            'chunks' => count($chunks),
                            'total_rows' => count($voters),
                            'duration_ms' => round((microtime(true) - $t_vat_all) * 1000, 2)
                        ]);
                    } else {
                        foreach ($chunks as $i => $chunk) {
                            if ($strategy === 'derived') {
                                $values = implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS id'));
                                $sql = sprintf($sqlBaseDerived, $values, $baseWhere);
                                $params = array_merge($chunk, $baseParams);
                            } elseif ($strategy === 'vm_in') {
                                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                                $sql = sprintf($sqlBaseVmIn, $baseWhere, $placeholders);
                                $params = array_merge($baseParams, $chunk);
                            } else { // 'in'
                                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                                $sql = sprintf($sqlBaseIn, $baseWhere, $placeholders);
                                $params = array_merge($baseParams, $chunk);
                            }

                        if ($debug) {
                            $dbg_sql = $sql;
                            foreach ($params as $v) {
                                $pos = strpos($dbg_sql, '?');
                                if ($pos !== false) {
                                    $rep = is_numeric($v) ? (string)$v : "'" . addslashes($v) . "'";
                                    $dbg_sql = substr_replace($dbg_sql, $rep, $pos, 1);
                                }
                            }
                            debug_log('VAT SQL (chunk) ' . ($i + 1) . ' [' . $strategy . ']', 'secondary', $dbg_sql);

                            // EXPLAIN plan
                            try {
                                $explainStmt = $vat_pdo->prepare('EXPLAIN ' . $sql);
                                $explainStmt->execute($params);
                                $plan = $explainStmt->fetchAll(PDO::FETCH_ASSOC);
                                debug_log('VAT EXPLAIN (chunk) ' . ($i + 1) . ' [' . $strategy . ']', 'info', $plan);
                            } catch (Exception $e) {
                                debug_log('VAT EXPLAIN error', 'warning', $e->getMessage());
                            }
                        }

                        $stmt = $vat_pdo->prepare($sql);
                        $t_chunk = microtime(true);
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $voters = array_merge($voters, $rows);
                        if ($debug) {
                            debug_log('VAT query chunk', 'info', [
                                'chunk_index' => $i + 1,
                                'chunk_size' => count($chunk),
                                'rows_returned' => count($rows),
                                'duration_ms' => round((microtime(true) - $t_chunk) * 1000, 2)
                            ]);
                        }
                    }
                    debug_log('VAT query total', 'info', [
                        'chunks' => count($chunks),
                        'total_rows' => count($voters),
                        'duration_ms' => round((microtime(true) - $t_vat_all) * 1000, 2)
                    ]);

                    // Refresh cache for the missing addresses: delete then insert
                    try {
                        if (!empty($missing_ids)) {
                            $delPlace = implode(',', array_fill(0, count($missing_ids), '?'));
                            $delSql = "DELETE FROM cached_voters WHERE county = ? AND address_id IN ($delPlace)";
                            $dstmt = $geo_pdo->prepare($delSql);
                            $dstmt->execute(array_merge([$county], $missing_ids));

                            if (!empty($voters)) {
                                // Insert in batches
                                $insRows = [];
                                foreach ($voters as $vr) {
                                    $insRows[] = [
                                        $county,
                                        $vr['address_id'] ?? null,
                                        $vr['VoterID'] ?? null,
                                        $vr['Voter_Name'] ?? null,
                                        $vr['First_Name'] ?? null,
                                        $vr['Last_Name'] ?? null,
                                        $vr['Email_Address'] ?? null,
                                        $vr['Phone_Number'] ?? null,
                                        isset($vr['Birthday']) && $vr['Birthday'] ? date('Y-m-d', strtotime($vr['Birthday'])) : null,
                                        $vr['Party'] ?? null,
                                        $vr['Voter_Address'] ?? null,
                                    ];
                                }
                                $insChunk = 200;
                                for ($i = 0; $i < count($insRows); $i += $insChunk) {
                                    $slice = array_slice($insRows, $i, $insChunk);
                                    $place = implode(',', array_fill(0, count($slice), '(?,?,?,?,?,?,?,?,?,?,?)'));
                                    $isql = "INSERT INTO cached_voters (
                                        county, address_id, voter_id, voter_name, first_name, last_name,
                                        email_address, phone_number, birth_date, party, voter_address
                                    ) VALUES $place";
                                    $params = [];
                                    foreach ($slice as $row) { $params = array_merge($params, $row); }
                                    $istmt = $geo_pdo->prepare($isql);
                                    $istmt->execute($params);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        debug_log('Cache write error', 'warning', $e->getMessage());
                    }
                    }

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
                debug_log('Total search time', 'info', sprintf('%.2f ms', (microtime(true) - $t_all) * 1000));
            
        } else {
            $error = "Geocoding failed: Address not recognized by Census API.";
        }
    }
}

$display_fields = [
    'VoterID'       => 'Voter ID',
    'Last_Name'     => 'Last Name',
    'First_Name'    => 'First Name',
    'Voter_Address' => 'Address',
    'Phone_Number'  => 'Phone',
    'Birthday'      => 'Birthday',
    'Email_Address' => 'Email',
    'Party'         => 'Party',
];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Voters Within Radius</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <style>
    #map { height: 500px; width: 100%; margin-top: 20px; border: 1px solid #ccc; }
    @media print {
        @page { size: landscape; margin: 0.25in; }
        body { margin: 0.25in; font-size: 10pt; }
        .notes-column { display: table-cell !important; }
    }
    @media screen { .notes-column { display: none; } }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h2 class="text-center mb-4">Find Voters Within a Radius</h2>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="">
                            <?php $selected_county = $_POST['county'] ?? ($geoip_county ?? ($counties[0] ?? '')); ?>
                            <div class="mb-3">
                                <label for="county" class="form-label">Select County:</label>
                                <select class="form-select" name="county" id="county" required>
                                    <?php foreach ($counties as $code): ?>
                                        <option value="<?= $code ?>" <?= $code === $selected_county ? 'selected' : '' ?>><?= $code ?></option>
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
                                    <?php foreach ($party_options as $pcode => $label): ?>
                                        <option value="<?= $pcode ?>" <?= $pcode === $selected_party ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="address" class="form-label">Enter Address:</label>
                                <input type="text" class="form-control" name="address" id="address" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>" placeholder="e.g., 1600 Pennsylvania Ave NW, Washington, DC 20500" required>
                                <div class="form-text">Include street number and name, city, state, and ZIP for best results.</div>
                            </div>

                            <div class="mb-3">
                                <label for="radius" class="form-label">Enter Radius (miles):</label>
                                <input type="number" class="form-control" step="0.01" name="radius" id="radius" value="<?php echo isset($_POST['radius']) ? htmlspecialchars($_POST['radius']) : '.1'; ?>" required>
                            </div>

                            <button type="submit" class="btn btn-primary">Search</button>
                        </form>
                        <p class="mt-2 small text-muted">Start with 0.1 miles and increase slowly for manageable results.</p>
                        <div id="searchingIndicator" class="alert alert-info d-none mt-3">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            <strong>Searching...</strong> Please wait while we retrieve nearby voters.
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div id="map"></div>
            </div>
        </div>

<?php if (!empty($voters)): ?>
            <div class="table-container mt-4">
                <div class="d-flex align-items-center mb-2">
                    <h3 class="mb-0">Voters Within <?php echo $radius; ?> Miles of <?php echo htmlspecialchars($address); ?></h3>
                    <select id="sortOption" class="form-select form-select-sm w-auto ms-3">
                        <option value="optimized" selected>Optimized Route</option>
                        <option value="street">Street Name</option>
                    </select>
                    <button onclick="window.print();" class="btn btn-primary btn-sm ms-auto">Print Page</button>
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
                                <td><?= htmlspecialchars($voter['Birthday'] ? date('m/d', strtotime($voter['Birthday'])) : '') ?></td>
                                <td><?= htmlspecialchars($voter['Email_Address'] ?? '') ?></td>
                                <td><?= htmlspecialchars($voter['Party'] ?? '') ?></td>
                                <td class="notes-column">&nbsp;</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
<?php elseif ($_SERVER["REQUEST_METHOD"] === "POST" && empty($error)): ?>
            <div class="alert alert-warning mt-4">
                No voters found within <?php echo htmlspecialchars((string)$radius); ?> miles of
                "<?php echo htmlspecialchars($address); ?>" for county <?php echo htmlspecialchars($county); ?><?php echo ($party && $party !== 'ALL') ? ", party $party" : ''; ?>.
                <br>
                Tip: ensure the address is within the selected Florida county, and try a slightly larger radius.
            </div>
<?php endif; ?>
    </div>
    <?php if (!empty($debug_log)): ?>
    <div class="container mt-4">
        <?php foreach ($debug_log as $dbg): ?>
            <div class="card border-<?= $dbg['variant'] ?> mb-3">
                <div class="card-header bg-<?= $dbg['variant'] ?> text-white fw-bold">
                    Debug: <?= htmlspecialchars($dbg['title']) ?>
                </div>
                <div class="card-body">
                    <pre class="mb-0"><?php echo htmlspecialchars(is_string($dbg['content']) ? $dbg['content'] : print_r($dbg['content'], true)); ?></pre>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <script>
    const voters = <?php echo json_encode($voters); ?>;
    function haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371, toRad = d => d * Math.PI/180;
        const dLat = toRad(lat2 - lat1), dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)**2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }
    function computeOptimalRoute(points) {
        if (!points.length) return [];
        const n = points.length, dist = Array.from({length:n},()=>Array(n).fill(0));
        for (let i=0;i<n;i++) for (let j=i+1;j<n;j++) {
            const d=haversineDistance(points[i].Latitude,points[i].Longitude,points[j].Latitude,points[j].Longitude);
            dist[i][j]=dist[j][i]=d;
        }
        const route=[0], visited=new Array(n).fill(false); visited[0]=true;
        for (let i=1;i<n;i++){let last=route[route.length-1], nearest=-1;
            for(let j=0;j<n;j++){if(!visited[j]&&(nearest===-1||dist[last][j]<dist[last][nearest]))nearest=j;}
            route.push(nearest); visited[nearest]=true;
        }
        return route.map(idx=>points[idx]);
    }
    function sortByStreet(points) {
        return points.slice().sort((a,b)=>{
            const addrA=(a.Voter_Address||'').split('\n')[0], addrB=(b.Voter_Address||'').split('\n')[0];
            const streetA=addrA.replace(/^\d+\s*/,'').toLowerCase(), streetB=addrB.replace(/^\d+\s*/,'').toLowerCase();
            if(streetA!==streetB)return streetA.localeCompare(streetB);
            return (parseInt(addrA)||0)-(parseInt(addrB)||0);
        });
    }
    document.addEventListener("DOMContentLoaded",()=>{
        const lat=<?php echo $latitude ?? 29.0283; ?>, lon=<?php echo $longitude ?? -81.3031; ?>;
        const radiusInMeters=<?php echo isset($radius)?$radius*1609.34:1609.34; ?>;
        const map=L.map('map').setView([lat,lon],14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
        L.marker([lat,lon]).addTo(map).bindPopup("Search Address").openPopup();
        const radiusCircle = L.circle([lat,lon],{radius:radiusInMeters,color:'red',fillOpacity:0.2}).addTo(map);
        // Fit the map view to just outside the radius
        try {
            const b = radiusCircle.getBounds();
            if (b && b.isValid && b.isValid()) {
                map.fitBounds(b.pad(0.15));
            } else {
                map.setView([lat,lon], 15);
            }
        } catch (e) {
            map.setView([lat,lon], 15);
        }
        const optimized=computeOptimalRoute(voters), streetSorted=sortByStreet(voters);
        const markerLayer=L.layerGroup().addTo(map); let routeLine=null;

        // Party color map
        const partyColor = (p)=>{
            switch((p||'').toUpperCase()){case 'REP': return '#d32f2f'; case 'DEM': return '#1976d2'; case 'NPA': return '#616161'; default: return '#8e8e8e';}
        };

        // Legend control
        const legend = L.control({position:'bottomright'});
        legend.onAdd = function(){
            const div = L.DomUtil.create('div','card p-2');
            div.style.fontSize='12px';
            div.innerHTML = `
                <div class="fw-bold mb-1">Legend</div>
                <div><span style="display:inline-block;width:10px;height:10px;background:#1976d2;border-radius:50%;margin-right:6px"></span>Democrat</div>
                <div><span style="display:inline-block;width:10px;height:10px;background:#d32f2f;border-radius:50%;margin-right:6px"></span>Republican</div>
                <div><span style="display:inline-block;width:10px;height:10px;background:#616161;border-radius:50%;margin-right:6px"></span>No Party</div>
            `;
            return div;
        };
        legend.addTo(map);
        // Controls
        const routeToggle = (function(){
            const sel = document.getElementById('sortOption');
            let cb = document.getElementById('showRoute');
            if (!cb && sel && sel.parentElement){
                cb = document.createElement('input');
                cb.type='checkbox'; cb.id='showRoute'; cb.className='form-check-input ms-3'; cb.checked=true;
                const lbl = document.createElement('label');
                lbl.className='form-check-label ms-1'; lbl.setAttribute('for','showRoute'); lbl.textContent='Show route';
                const wrap = document.createElement('div'); wrap.className='form-check d-flex align-items-center ms-2';
                wrap.appendChild(cb); wrap.appendChild(lbl);
                sel.parentElement.appendChild(wrap);
            }
            return cb;
        })();

        function render(list){
            const tbody=document.querySelector('table tbody');
            if(tbody){const rows=Array.from(tbody.querySelectorAll('tr')), rowMap={};
                rows.forEach(r=>rowMap[r.dataset.voterId]=r); tbody.innerHTML='';
                list.forEach(v=>{const row=rowMap[v.VoterID]; if(row)tbody.appendChild(row);});
            }
            markerLayer.clearLayers(); if(routeLine){map.removeLayer(routeLine); routeLine=null;}
            const path=[]; list.forEach((v,idx)=>{path.push([v.Latitude,v.Longitude]);
                const cm = L.circleMarker([v.Latitude,v.Longitude],{radius:6,weight:2,color:'#333',fillColor:partyColor(v.Party),fillOpacity:0.9});
                cm.bindPopup(`${idx+1}. ${v.Voter_Name}<br>${v.Voter_Address}`);
                markerLayer.addLayer(cm);
            });
            if(path.length && routeToggle && routeToggle.checked) routeLine=L.polyline(path,{color:'#1565c0',weight:3,opacity:0.8}).addTo(map);
        }
        const sortSel=document.getElementById('sortOption');
        if(sortSel){sortSel.addEventListener('change',()=>{render(sortSel.value==='street'?streetSorted:optimized);});}
        if(routeToggle){routeToggle.addEventListener('change',()=>{render((sortSel && sortSel.value==='street')?streetSorted:optimized);});}
        render(optimized);
    });
    </script>
    <script>
    const formEl = document.querySelector('form');
    if (formEl) {
        formEl.addEventListener('submit', () => {
            const el = document.getElementById('searchingIndicator');
            if (el) el.classList.remove('d-none');
        });
    }
    </script>
    
</body>
</html>
