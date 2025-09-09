# Voter Radius Lookup Tool

## Setup Instructions

1. **Install dependencies via Composer**  
   Run this in the project directory:
   ```bash
   composer install
   ```

2. **Create your `.env` file**  
   Copy `.env.example` and fill in your real credentials:
   ```bash
   cp .env.example .env
   ```

3. **Make sure the web server has access to `vendor/`**  
   If deploying, include the `vendor/` folder or run `composer install` on the server.

## Features

- Securely loads database credentials from `.env`
- Uses Leaflet.js to map voters within a radius
- Connects to geocoded address and voter databases
- Displays a "Searching..." indicator when form is submitted

## Performance & Debug

- Geo prefilter uses `MBRContains` on `geocode_db.geocoded_addresses.location` with a SPATIAL INDEX, then refines radius via `ST_Distance_Sphere` (miles).
- VAT join strategies (choose fastest for your DB):
  - Default: `vm_in` (filters by `vm.voter_addr_id IN (...)` and joins demographics by primary key).
  - Alternatives: `in` (filters by `mva.id IN (...)`) and `derived` (derived id list). Toggle with `?vat_strategy=vm_in|in|derived`.
- Debug timing/panels: add `?debug=1` to see SQL, timings, and EXPLAIN plans.

### Caching (geocode_db.cached_voters)

- The app caches voter rows by `(county, address_id, voter_id)` and reuses them for later searches.
- TTL: configurable via `.env` `CACHE_TTL_DAYS` (default 30). Cache reads require `updated_at >= NOW() - INTERVAL CACHE_TTL_DAYS DAY`.
- Forced refresh: add `?refresh=1` to bypass cache for a single search and refresh cache for those addresses.
- Party-aware: cache respects party filter (ALL returns all parties; party-specific searches only reuse rows for that party).

Schema (auto-created):

```
CREATE TABLE cached_voters (
  county CHAR(3) NOT NULL,
  address_id INT NOT NULL,
  voter_id INT NOT NULL,
  voter_name VARCHAR(100),
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email_address VARCHAR(100),
  phone_number VARCHAR(20),
  birth_date DATE,
  party CHAR(10),
  voter_address TEXT,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (county, address_id, voter_id),
  INDEX idx_addr (address_id),
  INDEX idx_party (party)
);
```

Pre-populating the cache (optional):

- Use the app with `?refresh=1` over the area to warm the cache automatically.
- Or, if cross-DB privileges allow, run a direct insert from VAT:

```
INSERT INTO geocode_db.cached_voters (
  county, address_id, voter_id, voter_name, first_name, last_name,
  email_address, phone_number, birth_date, party, voter_address
)
SELECT vm.county, mva.id, vm.voter_id,
       d.voter_name, d.first_name, d.last_name,
       d.email_address, d.phone_number, d.birth_date,
       vm.party,
       CONCAT_WS(CHAR(10), mva.street_address, TRIM(CONCAT_WS(' ', mva.address_line2, mva.apt_number)))
FROM fddc_vat_sql.voter_master vm
JOIN fddc_vat_sql.master_voter_address mva ON vm.voter_addr_id = mva.id
JOIN fddc_vat_sql.demographics d ON d.id = vm.demographics_id AND d.county = vm.county
WHERE vm.county = 'VOL' AND vm.exp_date = '2100-12-31' AND mva.id IN (/* address ids */);
```

If cross-DB writes are not allowed, a small CLI script can batch-read from VAT and insert into `cached_voters`; ask if you want this added.



---

## 🗃️ Database Schema Requirements

> ⚠️ These are **minimal table definitions** required for this app to function.  
> In the current development setup, data is distributed across **two databases** (`geocode_db` and `voter_data`).  
> This is part of an exploratory process to integrate multiple data sources during development.

---

### 📍 `geocode_db.geocoded_addresses`

Used to store geocoded addresses for radius-based spatial lookup.

```sql
CREATE TABLE geocoded_addresses (
    address_id INT PRIMARY KEY,
    lat DECIMAL(10, 7),
    lon DECIMAL(10, 7),
    full_address VARCHAR(255),
    location POINT GENERATED ALWAYS AS (POINT(lon, lat)) STORED,
    SPATIAL INDEX (location)
);
```

- Requires MySQL 8.0+ for spatial indexing and `ST_Distance_Sphere`.

---

### 🗳️ `voter_data.voter_master`

Stores core voter registration metadata.

```sql
CREATE TABLE voter_master (
    voter_id VARCHAR(20) PRIMARY KEY,
    voter_addr_id INT,
    county VARCHAR(10),
    exp_date DATE,
    party VARCHAR(10),
    INDEX (county),
    INDEX (exp_date)
);
```

---

### 👤 `voter_data.demographics`

Contains contact and demographic data for voters.

```sql
CREATE TABLE demographics (
    voter_id VARCHAR(20) PRIMARY KEY,
    voter_name VARCHAR(100),
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    email_address VARCHAR(100),
    phone_number VARCHAR(20),
    birth_date DATE,
    party VARCHAR(10)
);
```

---

### 🏠 `voter_data.master_voter_address`

Links voter IDs to physical addresses.

```sql
CREATE TABLE master_voter_address (
    id INT PRIMARY KEY,
    street_address VARCHAR(255),
    address_line2 VARCHAR(100),
    apt_number VARCHAR(20)
);
```

---

### 🔗 Query Relationships & Logic

- Joins:
  - `voter_master.voter_addr_id = master_voter_address.id`
  - `voter_master.voter_id = demographics.voter_id`
- Filters:
  - `vm.county = ?`
  - `vm.exp_date = '2100-12-31'`
  - `mva.id IN (?, ?, ...)`
- Distance logic:
  ```sql
  ST_Distance_Sphere(POINT(lon, lat), POINT(:lng, :lat)) / 1609.34
  ```
