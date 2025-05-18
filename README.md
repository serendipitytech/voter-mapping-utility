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

