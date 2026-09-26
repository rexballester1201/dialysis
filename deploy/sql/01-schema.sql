-- ============================================================
--  01 - BASELINE SCHEMA
-- ============================================================
--  66 tables, 12 views, 6 triggers, 15 generated columns, 64 CHECK constraints.
--
--  Import into the database cPanel already created for you.
--  Do NOT prepend CREATE DATABASE or USE -- phpMyAdmin runs
--  statements against the database you have selected, and a
--  cPanel MySQL user has no privilege to create one.
-- ============================================================

-- =====================================================================
-- DIALYSIS CENTRE MANAGEMENT SYSTEM — MySQL 8 schema
-- Stack: Laravel 12 (API) + MySQL 8.0.16+ + React SPA/PWA
-- Validated on MySQL 8.0.46
-- =====================================================================
-- Porting notes (PostgreSQL -> MySQL). Read these before changing anything.
--
--  1. NO SCHEMAS. Postgres core/clinical/ops/billing/audit become one
--     database with Laravel-plural table names. The module boundary now
--     lives in PHP namespaces (app/Domain/*), not in the database.
--
--  2. BIGINT PKs, not UUIDs. InnoDB clusters on the PK; random UUID PKs
--     fragment the clustered index and bloat every secondary index.
--     Externally-visible rows carry a ULID `public_id` for URLs.
--
--  3. DATETIME(3), not TIMESTAMP. MySQL TIMESTAMP dies on 2038-01-19.
--     Dialysis records are retained 10-15 years, so 2038 is inside the
--     retention window of data being written today. All times are UTC;
--     the display timezone lives in `facilities.timezone`.
--
--  4. NO EXCLUSION CONSTRAINTS. Postgres guaranteed non-overlapping
--     prescription periods with a GiST EXCLUDE. MySQL needs a trigger
--     (see hd_prescriptions_*_overlap below) plus a SELECT ... FOR UPDATE
--     in the application service. Both layers, not one.
--
--  5. NO PARTIAL INDEXES. Replaced with the standard MySQL idiom: a
--     STORED generated column that evaluates to NULL when the predicate
--     is false, plus a plain UNIQUE index. MySQL ignores NULLs in unique
--     indexes, so the constraint applies only to the rows we care about.
--
--  6. NO ARRAY COLUMNS. `text[]` becomes a junction table (station_cohorts)
--     and weekday sets become a 7-bit mask (bit 0 = Monday).
--
--  7. CHECK CONSTRAINTS CANNOT USE CURRENT_DATE. MySQL rejects
--     non-deterministic functions in CHECK. Rules like "birth_date is not
--     in the future" move to Laravel form-request validation.
--
--  8. NO ROW-SERIALISING TRIGGERS. Postgres used to_jsonb(NEW) for the
--     audit trail. MySQL cannot do that, so auditing moves into Laravel
--     model observers. Consequence: direct SQL edits bypass the audit
--     log. Grant no human a write-capable MySQL account.
--
--  9. citext -> the default utf8mb4_0900_ai_ci collation is already
--     case-insensitive, so email uniqueness behaves the same.
--
-- MariaDB: 10.11 accepts most of this, but JSON is an alias for LONGTEXT
-- (no validation), and stored generated columns using CASE/CONCAT over
-- other columns need re-testing. Prefer MySQL 8.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;




-- =====================================================================
-- CORE
-- =====================================================================

CREATE TABLE facilities (
  id              TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  name            VARCHAR(160) NOT NULL,
  legal_name      VARCHAR(200) NULL,
  licence_no      VARCHAR(60)  NULL,
  accreditation_no VARCHAR(60) NULL,
  country_code    CHAR(2)      NOT NULL,
  timezone        VARCHAR(64)  NOT NULL DEFAULT 'Asia/Manila',
  currency        CHAR(3)      NOT NULL DEFAULT 'PHP',
  address_line1   VARCHAR(160) NULL,
  address_line2   VARCHAR(160) NULL,
  city            VARCHAR(80)  NULL,
  province        VARCHAR(80)  NULL,
  postal_code     VARCHAR(20)  NULL,
  phone           VARCHAR(40)  NULL,
  email           VARCHAR(160) NULL,
  station_count   SMALLINT UNSIGNED NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT facilities_singleton CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE stations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL,
  kind        VARCHAR(20)  NOT NULL DEFAULT 'standard',
  room        VARCHAR(60)  NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  notes       VARCHAR(255) NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY stations_code_uq (code),
  CONSTRAINT stations_kind_ck CHECK (kind IN ('standard','isolation','acute','training','reserve'))
) ENGINE=InnoDB;

-- Replaces Postgres `allowed_cohort text[]`.
-- No rows for a station = no restriction.
CREATE TABLE station_cohorts (
  station_id  INT UNSIGNED NOT NULL,
  cohort      VARCHAR(10)  NOT NULL,
  PRIMARY KEY (station_id, cohort),
  CONSTRAINT station_cohorts_station_fk FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
  CONSTRAINT station_cohorts_ck CHECK (cohort IN ('clean','hbv','hcv'))
) ENGINE=InnoDB;

-- weekday_mask: bit 0 = Monday ... bit 6 = Sunday.  0b0111111 = Mon-Sat = 63
CREATE TABLE shifts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(10)  NOT NULL,
  name         VARCHAR(60)  NOT NULL,
  starts_at    TIME         NOT NULL,
  ends_at      TIME         NOT NULL,
  weekday_mask TINYINT UNSIGNED NOT NULL DEFAULT 63,
  is_overnight TINYINT(1) GENERATED ALWAYS AS (ends_at <= starts_at) STORED,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  UNIQUE KEY shifts_code_uq (code),
  CONSTRAINT shifts_mask_ck CHECK (weekday_mask BETWEEN 1 AND 127)
) ENGINE=InnoDB;

CREATE TABLE roles (
  code        VARCHAR(40)  NOT NULL PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE staff (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id          CHAR(26)     NOT NULL,          -- ULID, used in URLs
  employee_no        VARCHAR(30)  NULL,
  first_name         VARCHAR(80)  NOT NULL,
  middle_name        VARCHAR(80)  NULL,
  last_name          VARCHAR(80)  NOT NULL,
  suffix             VARCHAR(20)  NULL,
  full_name          VARCHAR(280) GENERATED ALWAYS AS (
                       TRIM(CONCAT_WS(' ', first_name, middle_name, last_name, suffix))) STORED,
  licence_no         VARCHAR(40)  NULL,
  licence_expires_on DATE         NULL,
  specialty          VARCHAR(80)  NULL,
  email              VARCHAR(160) NULL,
  phone              VARCHAR(40)  NULL,
  password           VARCHAR(255) NULL,               -- Laravel Hash::make
  clinical_pin_hash  VARCHAR(255) NULL,               -- fast bedside re-auth
  two_factor_secret  TEXT         NULL,
  remember_token     VARCHAR(100) NULL,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at      DATETIME(3)  NULL,
  failed_logins      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until       DATETIME(3)  NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at         DATETIME(3) NULL,
  UNIQUE KEY staff_public_id_uq (public_id),
  UNIQUE KEY staff_employee_no_uq (employee_no),
  UNIQUE KEY staff_email_uq (email),
  KEY staff_licence_expiry_idx (licence_expires_on)
) ENGINE=InnoDB;

CREATE TABLE role_staff (
  staff_id   BIGINT UNSIGNED NOT NULL,
  role_code  VARCHAR(40) NOT NULL,
  granted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  granted_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (staff_id, role_code),
  CONSTRAINT role_staff_staff_fk FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  CONSTRAINT role_staff_role_fk  FOREIGN KEY (role_code) REFERENCES roles(code),
  CONSTRAINT role_staff_granter_fk FOREIGN KEY (granted_by) REFERENCES staff(id)
) ENGINE=InnoDB;

CREATE TABLE patients (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id             CHAR(26)     NOT NULL,
  mrn                   VARCHAR(30)  NOT NULL,
  first_name            VARCHAR(80)  NOT NULL,
  middle_name           VARCHAR(80)  NULL,
  last_name             VARCHAR(80)  NOT NULL,
  suffix                VARCHAR(20)  NULL,
  full_name             VARCHAR(280) GENERATED ALWAYS AS (
                          TRIM(CONCAT_WS(' ', first_name, middle_name, last_name, suffix))) STORED,
  birth_date            DATE         NOT NULL,
  sex                   VARCHAR(10)  NOT NULL DEFAULT 'unknown',
  blood_type            VARCHAR(6)   NULL,
  civil_status          VARCHAR(20)  NULL,
  nationality           VARCHAR(60)  NULL,
  religion              VARCHAR(60)  NULL,
  occupation            VARCHAR(80)  NULL,
  mobile                VARCHAR(40)  NULL,
  landline              VARCHAR(40)  NULL,
  email                 VARCHAR(160) NULL,
  address_line1         VARCHAR(160) NULL,
  address_line2         VARCHAR(160) NULL,
  barangay              VARCHAR(80)  NULL,
  city                  VARCHAR(80)  NULL,
  province              VARCHAR(80)  NULL,
  postal_code           VARCHAR(20)  NULL,
  first_dialysis_date   DATE         NULL,
  first_session_here_on DATE         NULL,
  referring_physician   VARCHAR(120) NULL,
  primary_nephrologist_id BIGINT UNSIGNED NULL,
  status                VARCHAR(24)  NOT NULL DEFAULT 'active',
  status_changed_on     DATE         NULL,
  photo_path            VARCHAR(255) NULL,
  notes                 TEXT         NULL,
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by            BIGINT UNSIGNED NULL,
  updated_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by            BIGINT UNSIGNED NULL,
  deleted_at            DATETIME(3) NULL,             -- soft delete; never hard-delete a patient
  UNIQUE KEY patients_public_id_uq (public_id),
  UNIQUE KEY patients_mrn_uq (mrn),
  KEY patients_name_idx (last_name, first_name),
  KEY patients_status_idx (status, deleted_at),
  CONSTRAINT patients_nephrologist_fk FOREIGN KEY (primary_nephrologist_id) REFERENCES staff(id),
  CONSTRAINT patients_sex_ck CHECK (sex IN ('male','female','other','unknown')),
  CONSTRAINT patients_status_ck CHECK (status IN
    ('active','on_hold','hospitalised','transferred_out','transplanted',
     'recovered_function','deceased','lost_to_followup','discontinued'))
) ENGINE=InnoDB;

CREATE TABLE patient_identifiers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id  BIGINT UNSIGNED NOT NULL,
  id_type     VARCHAR(30)  NOT NULL,                  -- philhealth, mykad, bpjs, passport
  id_value    VARCHAR(60)  NOT NULL,
  valid_until DATE         NULL,
  is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY patient_identifiers_uq (id_type, id_value),
  KEY patient_identifiers_patient_idx (patient_id),
  CONSTRAINT patient_identifiers_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE patient_contacts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id   BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL,
  relationship VARCHAR(60)  NULL,
  mobile       VARCHAR(40)  NULL,
  phone        VARCHAR(40)  NULL,
  email        VARCHAR(160) NULL,
  address      VARCHAR(255) NULL,
  is_emergency TINYINT(1)   NOT NULL DEFAULT 1,
  is_guarantor TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  KEY patient_contacts_patient_idx (patient_id),
  CONSTRAINT patient_contacts_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE patient_status_histories (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id   BIGINT UNSIGNED NOT NULL,
  status       VARCHAR(24) NOT NULL,
  effective_on DATE        NOT NULL,
  reason       VARCHAR(255) NULL,
  destination  VARCHAR(160) NULL,
  recorded_by  BIGINT UNSIGNED NULL,
  recorded_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY psh_patient_idx (patient_id, effective_on DESC),
  CONSTRAINT psh_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT psh_staff_fk   FOREIGN KEY (recorded_by) REFERENCES staff(id)
) ENGINE=InnoDB;

CREATE TABLE consents (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id    BIGINT UNSIGNED NOT NULL,
  consent_type  VARCHAR(40) NOT NULL,
  granted       TINYINT(1)  NOT NULL,
  signed_on     DATE        NOT NULL,
  expires_on    DATE        NULL,
  document_path VARCHAR(255) NULL,
  witnessed_by  BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY consents_patient_idx (patient_id, consent_type),
  CONSTRAINT consents_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT consents_staff_fk   FOREIGN KEY (witnessed_by) REFERENCES staff(id)
) ENGINE=InnoDB;

-- =====================================================================
-- CLINICAL
-- =====================================================================

CREATE TABLE diagnosis_refs (
  code        VARCHAR(12)  NOT NULL PRIMARY KEY,     -- ICD-10
  description VARCHAR(255) NOT NULL,
  category    VARCHAR(30)  NULL
) ENGINE=InnoDB;

CREATE TABLE patient_diagnoses (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id       BIGINT UNSIGNED NOT NULL,
  code             VARCHAR(12) NULL,
  free_text        VARCHAR(255) NULL,
  is_primary_renal TINYINT(1)  NOT NULL DEFAULT 0,
  onset_date       DATE NULL,
  resolved_date    DATE NULL,
  recorded_by      BIGINT UNSIGNED NULL,
  recorded_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- Partial-unique replacement: NULL for every row we do not want constrained,
  -- so MySQL's "NULLs never collide" rule gives us the Postgres behaviour.
  one_primary_renal BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN is_primary_renal = 1 AND resolved_date IS NULL THEN patient_id END) STORED,
  UNIQUE KEY pd_one_primary_renal_uq (one_primary_renal),
  KEY pd_patient_idx (patient_id),
  -- NOTE: no ON DELETE CASCADE. MySQL forbids CASCADE/SET NULL on a column
  -- that feeds a generated column (patient_id feeds one_primary_renal above).
  -- That suits us: clinical rows must never disappear because of a cascade.
  CONSTRAINT pd_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT pd_code_fk    FOREIGN KEY (code) REFERENCES diagnosis_refs(code),
  CONSTRAINT pd_content_ck CHECK (code IS NOT NULL OR free_text IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE allergies (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id  BIGINT UNSIGNED NOT NULL,
  substance   VARCHAR(160) NOT NULL,
  reaction    VARCHAR(255) NULL,
  severity    VARCHAR(20)  NULL,
  onset_date  DATE NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  recorded_by BIGINT UNSIGNED NULL,
  recorded_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY allergies_patient_idx (patient_id, is_active),
  CONSTRAINT allergies_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT allergies_severity_ck CHECK (severity IN ('mild','moderate','severe','anaphylaxis'))
) ENGINE=InnoDB;

-- Serology drives station/machine segregation. Safety-critical.
CREATE TABLE serology_results (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id    BIGINT UNSIGNED NOT NULL,
  marker        VARCHAR(12) NOT NULL,
  result        VARCHAR(16) NOT NULL,
  titre         DECIMAL(10,2) NULL,
  specimen_date DATE NOT NULL,
  resulted_on   DATE NULL,
  lab_name      VARCHAR(120) NULL,
  document_path VARCHAR(255) NULL,
  recorded_by   BIGINT UNSIGNED NULL,
  recorded_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY serology_uq (patient_id, marker, specimen_date),
  KEY serology_latest_idx (patient_id, marker, specimen_date DESC),
  CONSTRAINT serology_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT serology_marker_ck CHECK (marker IN
    ('hbsag','anti_hbs','anti_hbc','anti_hcv','hcv_rna','hiv','vdrl','hbv_dna')),
  CONSTRAINT serology_result_ck CHECK (result IN
    ('reactive','non_reactive','indeterminate','pending'))
) ENGINE=InnoDB;

CREATE TABLE vascular_accesses (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id     BIGINT UNSIGNED NOT NULL,
  access_type    VARCHAR(24) NOT NULL,
  side           VARCHAR(10) NULL,
  site           VARCHAR(60) NULL,
  created_on     DATE NULL,
  first_used_on  DATE NULL,
  surgeon        VARCHAR(120) NULL,
  status         VARCHAR(16) NOT NULL DEFAULT 'planned',
  removed_on     DATE NULL,
  removal_reason VARCHAR(255) NULL,
  notes          TEXT NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY va_patient_idx (patient_id, status),
  CONSTRAINT va_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT va_type_ck CHECK (access_type IN
    ('avf','avg','tunnelled_cvc','non_tunnelled_cvc','permcath','graft_other','pd_catheter')),
  CONSTRAINT va_side_ck CHECK (side IS NULL OR side IN ('left','right','midline')),
  CONSTRAINT va_status_ck CHECK (status IN
    ('planned','maturing','in_use','standby','failed','removed','infected'))
) ENGINE=InnoDB;

CREATE TABLE access_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  access_id    BIGINT UNSIGNED NOT NULL,
  event_date   DATE NOT NULL,
  event_type   VARCHAR(40) NOT NULL,
  description  TEXT NULL,
  intervention TEXT NULL,
  outcome      VARCHAR(255) NULL,
  recorded_by  BIGINT UNSIGNED NULL,
  recorded_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ae_access_idx (access_id, event_date DESC),
  CONSTRAINT ae_access_fk FOREIGN KEY (access_id) REFERENCES vascular_accesses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE dry_weights (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id     BIGINT UNSIGNED NOT NULL,
  weight_kg      DECIMAL(6,2) NOT NULL,
  effective_from DATE NOT NULL,
  reason         VARCHAR(255) NULL,
  set_by         BIGINT UNSIGNED NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY dw_uq (patient_id, effective_from),
  CONSTRAINT dw_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT dw_range_ck CHECK (weight_kg BETWEEN 10 AND 400)
) ENGINE=InnoDB;

CREATE TABLE anthropometry_records (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id    BIGINT UNSIGNED NOT NULL,
  measured_on   DATE NOT NULL,
  height_cm     DECIMAL(5,1) NULL,
  bmi           DECIMAL(5,2) NULL,
  urea_volume_l DECIMAL(6,2) NULL,                  -- V for Kt/V (Watson)
  recorded_by   BIGINT UNSIGNED NULL,
  UNIQUE KEY anthro_uq (patient_id, measured_on),
  CONSTRAINT anthro_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Prescription. Postgres used daterange + GiST EXCLUDE; MySQL uses
-- half-open [effective_from, effective_to) plus the triggers below.
-- ---------------------------------------------------------------------
CREATE TABLE hd_prescriptions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id         BIGINT UNSIGNED NOT NULL,
  version            INT UNSIGNED NOT NULL DEFAULT 1,
  effective_from     DATE NOT NULL,
  effective_to       DATE NULL,                      -- NULL = open ended
  effective_to_x     DATE GENERATED ALWAYS AS (COALESCE(effective_to, '9999-12-31')) STORED,
  modality           VARCHAR(12) NOT NULL DEFAULT 'hd',
  sessions_per_week  DECIMAL(3,1) NOT NULL DEFAULT 3,
  duration_min       SMALLINT UNSIGNED NOT NULL,
  dialyzer_item_id   BIGINT UNSIGNED NULL,
  reuse_allowed      TINYINT(1) NOT NULL DEFAULT 0,
  max_reuse_count    SMALLINT UNSIGNED NULL,
  blood_flow_ml_min  SMALLINT UNSIGNED NULL,
  needle_gauge       SMALLINT UNSIGNED NULL,
  vascular_access_id BIGINT UNSIGNED NULL,
  dialysate_flow_ml_min SMALLINT UNSIGNED NULL DEFAULT 500,
  dialysate_na_mmol  DECIMAL(5,1) NULL,
  dialysate_k_mmol   DECIMAL(4,1) NULL,
  dialysate_ca_mmol  DECIMAL(4,2) NULL,
  dialysate_hco3_mmol DECIMAL(5,1) NULL,
  dialysate_glucose_mmol DECIMAL(5,2) NULL,
  dialysate_temp_c   DECIMAL(4,1) NULL,
  na_profile         VARCHAR(30) NULL,
  uf_profile         VARCHAR(30) NULL,
  max_uf_rate_ml_hr  SMALLINT UNSIGNED NULL,
  substitution_mode  VARCHAR(10) NULL,               -- HDF only
  substitution_vol_l DECIMAL(5,1) NULL,
  anticoagulant      VARCHAR(16) NOT NULL DEFAULT 'heparin',
  ac_loading_dose    DECIMAL(8,2) NULL,
  ac_maintenance_hr  DECIMAL(8,2) NULL,
  ac_unit            VARCHAR(12) NULL DEFAULT 'units',
  ac_stop_before_min SMALLINT UNSIGNED NULL,
  target_ktv         DECIMAL(4,2) NULL,
  target_urr_pct     DECIMAL(4,1) NULL,
  target_dry_weight_kg DECIMAL(6,2) NULL,
  prescribed_by      BIGINT UNSIGNED NULL,
  change_reason      VARCHAR(255) NULL,
  notes              TEXT NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by         BIGINT UNSIGNED NULL,
  UNIQUE KEY hd_rx_version_uq (patient_id, version),
  KEY hd_rx_patient_idx (patient_id, effective_from, effective_to_x),
  CONSTRAINT hd_rx_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT hd_rx_access_fk  FOREIGN KEY (vascular_access_id) REFERENCES vascular_accesses(id),
  CONSTRAINT hd_rx_staff_fk   FOREIGN KEY (prescribed_by) REFERENCES staff(id),
  CONSTRAINT hd_rx_period_ck  CHECK (effective_to IS NULL OR effective_to > effective_from),
  CONSTRAINT hd_rx_duration_ck CHECK (duration_min BETWEEN 30 AND 600),
  CONSTRAINT hd_rx_spw_ck     CHECK (sessions_per_week BETWEEN 0.5 AND 7),
  CONSTRAINT hd_rx_qb_ck      CHECK (blood_flow_ml_min IS NULL OR blood_flow_ml_min BETWEEN 50 AND 600),
  CONSTRAINT hd_rx_temp_ck    CHECK (dialysate_temp_c IS NULL OR dialysate_temp_c BETWEEN 33 AND 39),
  CONSTRAINT hd_rx_modality_ck CHECK (modality IN ('hd','hdf','hf','sled','ihd_acute','pd_capd','pd_apd')),
  CONSTRAINT hd_rx_ac_ck      CHECK (anticoagulant IN ('none','heparin','lmwh','citrate','saline_flush','other')),
  CONSTRAINT hd_rx_sub_ck     CHECK (substitution_mode IS NULL OR substitution_mode IN ('pre','post','mixed'))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TREATMENT SESSION — the heart of the system (89 columns in Postgres,
-- same shape here). Mirrors the paper flow sheet section by section.
-- ---------------------------------------------------------------------
CREATE TABLE treatment_sessions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id          CHAR(26)     NOT NULL,
  client_uuid        CHAR(26)     NULL,               -- ULID minted by the tablet; idempotency key
  patient_id         BIGINT UNSIGNED NOT NULL,
  session_date       DATE NOT NULL,
  shift_id           INT UNSIGNED NULL,
  station_id         INT UNSIGNED NULL,
  machine_id         INT UNSIGNED NULL,
  prescription_id    BIGINT UNSIGNED NULL,
  status             VARCHAR(16) NOT NULL DEFAULT 'scheduled',
  modality           VARCHAR(12) NOT NULL DEFAULT 'hd',
  session_no_lifetime INT UNSIGNED NULL,
  is_first_ever      TINYINT(1) NOT NULL DEFAULT 0,

  -- ---- PRE-DIALYSIS ----
  checked_in_at      DATETIME(3) NULL,
  pre_weight_kg      DECIMAL(6,2) NULL,
  dry_weight_kg      DECIMAL(6,2) NULL,
  idwg_kg            DECIMAL(7,2) GENERATED ALWAYS AS (pre_weight_kg - dry_weight_kg) STORED,
  pre_bp_sys         SMALLINT UNSIGNED NULL,
  pre_bp_dia         SMALLINT UNSIGNED NULL,
  pre_bp_sys_standing SMALLINT UNSIGNED NULL,
  pre_bp_dia_standing SMALLINT UNSIGNED NULL,
  pre_pulse          SMALLINT UNSIGNED NULL,
  pre_temp_c         DECIMAL(4,1) NULL,
  pre_resp_rate      SMALLINT UNSIGNED NULL,
  pre_spo2_pct       SMALLINT UNSIGNED NULL,
  pre_glucose_mmol   DECIMAL(5,2) NULL,
  pre_assessment     JSON NULL,                       -- structured nursing checklist
  pre_notes          TEXT NULL,

  -- ---- ACTUAL SETTINGS ----
  dialyzer_item_id   BIGINT UNSIGNED NULL,
  dialyzer_unit_id   BIGINT UNSIGNED NULL,
  dialyzer_use_no    SMALLINT UNSIGNED NULL,
  bloodline_lot_id   BIGINT UNSIGNED NULL,
  vascular_access_id BIGINT UNSIGNED NULL,
  needle_gauge       SMALLINT UNSIGNED NULL,
  cannulation_attempts SMALLINT UNSIGNED NULL DEFAULT 1,
  planned_duration_min SMALLINT UNSIGNED NULL,
  planned_uf_ml      INT UNSIGNED NULL,
  blood_flow_set_ml_min SMALLINT UNSIGNED NULL,
  dialysate_flow_ml_min SMALLINT UNSIGNED NULL,
  dialysate_na_mmol  DECIMAL(5,1) NULL,
  dialysate_k_mmol   DECIMAL(4,1) NULL,
  dialysate_ca_mmol  DECIMAL(4,2) NULL,
  dialysate_hco3_mmol DECIMAL(5,1) NULL,
  dialysate_temp_c   DECIMAL(4,1) NULL,
  anticoagulant      VARCHAR(16) NULL,
  ac_loading_dose    DECIMAL(8,2) NULL,
  ac_maintenance_hr  DECIMAL(8,2) NULL,
  ac_total_given     DECIMAL(8,2) NULL,
  priming_volume_ml  INT UNSIGNED NULL,

  -- ---- TIMING ----
  started_at         DATETIME(3) NULL,
  ended_at           DATETIME(3) NULL,
  actual_duration_min INT GENERATED ALWAYS AS (
                       TIMESTAMPDIFF(MINUTE, started_at, ended_at)) STORED,
  termination_reason VARCHAR(32) NULL,
  termination_notes  TEXT NULL,

  -- ---- POST-DIALYSIS ----
  post_weight_kg     DECIMAL(6,2) NULL,
  net_uf_ml          INT UNSIGNED NULL,
  total_intake_ml    INT UNSIGNED NULL,
  weight_loss_kg     DECIMAL(7,2) GENERATED ALWAYS AS (pre_weight_kg - post_weight_kg) STORED,
  post_bp_sys        SMALLINT UNSIGNED NULL,
  post_bp_dia        SMALLINT UNSIGNED NULL,
  post_bp_sys_standing SMALLINT UNSIGNED NULL,
  post_bp_dia_standing SMALLINT UNSIGNED NULL,
  post_pulse         SMALLINT UNSIGNED NULL,
  post_temp_c        DECIMAL(4,1) NULL,
  post_resp_rate     SMALLINT UNSIGNED NULL,
  post_spo2_pct      SMALLINT UNSIGNED NULL,
  blood_volume_processed_l DECIMAL(6,2) NULL,
  ktv                DECIMAL(4,2) NULL,
  ktv_method         VARCHAR(32) NULL,
  urr_pct            DECIMAL(4,1) NULL,
  pre_bun_mmol       DECIMAL(6,2) NULL,
  post_bun_mmol      DECIMAL(6,2) NULL,

  -- ---- DISPOSITION ----
  ambulation         VARCHAR(16) NULL,
  discharge_condition VARCHAR(16) NULL,
  discharged_at      DATETIME(3) NULL,
  discharge_notes    TEXT NULL,

  -- ---- STAFF & ATTESTATION ----
  primary_nurse_id   BIGINT UNSIGNED NULL,
  assisting_staff_id BIGINT UNSIGNED NULL,
  technician_id      BIGINT UNSIGNED NULL,
  physician_id       BIGINT UNSIGNED NULL,
  nurse_signed_by    BIGINT UNSIGNED NULL,
  nurse_signed_at    DATETIME(3) NULL,
  physician_signed_by BIGINT UNSIGNED NULL,
  physician_signed_at DATETIME(3) NULL,
  locked_at          DATETIME(3) NULL,                -- immutable after this

  -- ---- BILLING ----
  is_billable        TINYINT(1) NOT NULL DEFAULT 1,
  benefit_claim_id   BIGINT UNSIGNED NULL,

  -- ---- SYNC ----
  synced_at          DATETIME(3) NULL,                -- when the tablet's copy landed
  device_id          VARCHAR(64) NULL,

  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by         BIGINT UNSIGNED NULL,

  -- Partial-unique replacements. Both evaluate to NULL for cancelled/missed
  -- rows, so those may pile up on the same slot without colliding.
  slot_patient_key VARCHAR(64) GENERATED ALWAYS AS (
    CASE WHEN status NOT IN ('cancelled','missed')
         THEN CONCAT_WS('|', patient_id, session_date, COALESCE(shift_id,0)) END) STORED,
  slot_station_key VARCHAR(64) GENERATED ALWAYS AS (
    CASE WHEN status NOT IN ('cancelled','missed','refused') AND station_id IS NOT NULL
         THEN CONCAT_WS('|', station_id, session_date, COALESCE(shift_id,0)) END) STORED,

  UNIQUE KEY ts_public_id_uq (public_id),
  UNIQUE KEY ts_client_uuid_uq (client_uuid),
  UNIQUE KEY ts_slot_patient_uq (slot_patient_key),
  UNIQUE KEY ts_slot_station_uq (slot_station_key),
  KEY ts_date_idx (session_date),
  KEY ts_patient_idx (patient_id, session_date),
  KEY ts_status_idx (status, session_date),
  KEY ts_board_idx (session_date, shift_id, station_id),

  -- No CASCADE on patient_id / station_id / shift_id: they feed generated columns.
  CONSTRAINT ts_patient_fk    FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT ts_shift_fk      FOREIGN KEY (shift_id) REFERENCES shifts(id),
  CONSTRAINT ts_station_fk    FOREIGN KEY (station_id) REFERENCES stations(id),
  CONSTRAINT ts_rx_fk         FOREIGN KEY (prescription_id) REFERENCES hd_prescriptions(id),
  CONSTRAINT ts_access_fk     FOREIGN KEY (vascular_access_id) REFERENCES vascular_accesses(id),
  CONSTRAINT ts_nurse_fk      FOREIGN KEY (primary_nurse_id) REFERENCES staff(id),
  CONSTRAINT ts_physician_fk  FOREIGN KEY (physician_id) REFERENCES staff(id),

  CONSTRAINT ts_status_ck CHECK (status IN
    ('scheduled','checked_in','in_progress','completed','aborted','missed','cancelled','refused')),
  CONSTRAINT ts_term_ck CHECK (termination_reason IS NULL OR termination_reason IN
    ('completed_as_prescribed','patient_request','hypotension','clotting','machine_fault',
     'access_failure','medical_emergency','power_failure','transferred_to_hospital','other')),
  CONSTRAINT ts_ktv_method_ck CHECK (ktv_method IS NULL OR ktv_method IN
    ('single_pool_daugirdas','online_clearance','ionic','equilibrated')),
  CONSTRAINT ts_ambulation_ck CHECK (ambulation IS NULL OR ambulation IN
    ('unassisted','assisted','wheelchair','stretcher')),
  CONSTRAINT ts_discharge_ck CHECK (discharge_condition IS NULL OR discharge_condition IN
    ('stable','improved','unstable','referred','expired')),
  CONSTRAINT ts_time_ck CHECK (ended_at IS NULL OR started_at IS NULL OR ended_at >= started_at),
  CONSTRAINT ts_preweight_ck CHECK (pre_weight_kg IS NULL OR pre_weight_kg BETWEEN 10 AND 400)
) ENGINE=InnoDB;

-- Intra-dialytic observations. Append-only, keyed by (session, timestamp):
-- this is what makes offline sync conflict-free.
CREATE TABLE session_vitals (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id         BIGINT UNSIGNED NOT NULL,
  client_uuid        CHAR(26) NULL,                   -- idempotency key from the tablet
  recorded_at        DATETIME(3) NOT NULL,
  minutes_elapsed    SMALLINT UNSIGNED NULL,
  bp_sys             SMALLINT UNSIGNED NULL,
  bp_dia             SMALLINT UNSIGNED NULL,
  map_mmhg           DECIMAL(5,1) GENERATED ALWAYS AS (bp_dia + (bp_sys - bp_dia)/3.0) STORED,
  pulse              SMALLINT UNSIGNED NULL,
  temp_c             DECIMAL(4,1) NULL,
  resp_rate          SMALLINT UNSIGNED NULL,
  spo2_pct           SMALLINT UNSIGNED NULL,
  blood_flow_ml_min  SMALLINT UNSIGNED NULL,
  arterial_pressure_mmhg SMALLINT NULL,
  venous_pressure_mmhg   SMALLINT NULL,
  tmp_mmhg           SMALLINT NULL,
  dialysate_flow_ml_min SMALLINT UNSIGNED NULL,
  conductivity_ms_cm DECIMAL(5,2) NULL,
  dialysate_temp_c   DECIMAL(4,1) NULL,
  uf_rate_ml_hr      SMALLINT UNSIGNED NULL,
  uf_volume_ml       INT UNSIGNED NULL,
  rbv_pct            DECIMAL(5,1) NULL,
  heparin_given      DECIMAL(8,2) NULL,
  comment            VARCHAR(255) NULL,
  source             VARCHAR(16) NOT NULL DEFAULT 'manual',
  recorded_by        BIGINT UNSIGNED NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY sv_session_time_uq (session_id, recorded_at),
  UNIQUE KEY sv_client_uuid_uq (client_uuid),
  KEY sv_session_idx (session_id, recorded_at),
  CONSTRAINT sv_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id) ON DELETE CASCADE,
  CONSTRAINT sv_staff_fk   FOREIGN KEY (recorded_by) REFERENCES staff(id),
  CONSTRAINT sv_source_ck  CHECK (source IN ('manual','machine','device_import'))
) ENGINE=InnoDB;

CREATE TABLE event_refs (
  code          VARCHAR(30) NOT NULL PRIMARY KEY,
  label         VARCHAR(80) NOT NULL,
  category      VARCHAR(30) NULL,
  is_reportable TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE session_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id   BIGINT UNSIGNED NOT NULL,
  client_uuid  CHAR(26) NULL,
  occurred_at  DATETIME(3) NOT NULL,
  event_code   VARCHAR(30) NOT NULL,
  severity     VARCHAR(20) NOT NULL DEFAULT 'minor',
  description  TEXT NULL,
  intervention TEXT NULL,
  outcome      VARCHAR(255) NULL,
  reported_by  BIGINT UNSIGNED NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY se_client_uuid_uq (client_uuid),
  KEY se_session_idx (session_id, occurred_at),
  KEY se_code_idx (event_code, occurred_at),
  CONSTRAINT se_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id) ON DELETE CASCADE,
  CONSTRAINT se_code_fk    FOREIGN KEY (event_code) REFERENCES event_refs(code),
  CONSTRAINT se_staff_fk   FOREIGN KEY (reported_by) REFERENCES staff(id),
  CONSTRAINT se_severity_ck CHECK (severity IN ('minor','moderate','severe','life_threatening'))
) ENGINE=InnoDB;

CREATE TABLE session_notes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id  BIGINT UNSIGNED NOT NULL,
  client_uuid CHAR(26) NULL,
  note_type   VARCHAR(20) NOT NULL DEFAULT 'nursing',
  body        TEXT NOT NULL,
  author_id   BIGINT UNSIGNED NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  amends_id   BIGINT UNSIGNED NULL,                   -- append-only correction chain
  UNIQUE KEY sn_client_uuid_uq (client_uuid),
  KEY sn_session_idx (session_id, created_at),
  CONSTRAINT sn_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id) ON DELETE CASCADE,
  CONSTRAINT sn_amends_fk  FOREIGN KEY (amends_id) REFERENCES session_notes(id),
  CONSTRAINT sn_author_fk  FOREIGN KEY (author_id) REFERENCES staff(id),
  CONSTRAINT sn_type_ck    CHECK (note_type IN ('nursing','physician','dietitian','social'))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Medication
-- ---------------------------------------------------------------------
CREATE TABLE medication_refs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  generic_name  VARCHAR(120) NOT NULL,
  brand_name    VARCHAR(120) NULL,
  form          VARCHAR(40)  NULL,
  strength      VARCHAR(40)  NULL,
  unit          VARCHAR(20)  NULL,
  atc_code      VARCHAR(12)  NULL,
  is_pnf        TINYINT(1) NOT NULL DEFAULT 0,       -- on national formulary
  is_high_alert TINYINT(1) NOT NULL DEFAULT 0,       -- requires a witness
  UNIQUE KEY med_ref_uq (generic_name, brand_name, strength, form)
) ENGINE=InnoDB;

CREATE TABLE medication_orders (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id    BIGINT UNSIGNED NOT NULL,
  medication_id INT UNSIGNED NOT NULL,
  dose          DECIMAL(10,3) NOT NULL,
  dose_unit     VARCHAR(20) NOT NULL,
  route         VARCHAR(20) NOT NULL,
  frequency     VARCHAR(30) NOT NULL,                 -- every_session, weekly, TIW, PRN
  timing        VARCHAR(10) NULL,                     -- pre, intra, post
  indication    VARCHAR(160) NULL,
  start_date    DATE NOT NULL,
  end_date      DATE NULL,
  is_prn        TINYINT(1) NOT NULL DEFAULT 0,
  prn_criteria  VARCHAR(255) NULL,
  ordered_by    BIGINT UNSIGNED NULL,
  status        VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY mo_patient_idx (patient_id, status),
  CONSTRAINT mo_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT mo_med_fk     FOREIGN KEY (medication_id) REFERENCES medication_refs(id),
  CONSTRAINT mo_staff_fk   FOREIGN KEY (ordered_by) REFERENCES staff(id),
  CONSTRAINT mo_status_ck  CHECK (status IN ('active','held','discontinued','completed'))
) ENGINE=InnoDB;

CREATE TABLE medication_administrations (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id      BIGINT UNSIGNED NULL,
  patient_id      BIGINT UNSIGNED NOT NULL,
  order_id        BIGINT UNSIGNED NULL,
  medication_id   INT UNSIGNED NOT NULL,
  client_uuid     CHAR(26) NULL,
  dose            DECIMAL(10,3) NOT NULL,
  dose_unit       VARCHAR(20) NOT NULL,
  route           VARCHAR(20) NOT NULL,
  administered_at DATETIME(3) NOT NULL,
  timing          VARCHAR(10) NULL,
  lot_id          BIGINT UNSIGNED NULL,
  site            VARCHAR(60) NULL,
  given_by        BIGINT UNSIGNED NULL,
  witnessed_by    BIGINT UNSIGNED NULL,               -- mandatory for high-alert drugs
  not_given       TINYINT(1) NOT NULL DEFAULT 0,
  not_given_reason VARCHAR(255) NULL,
  reaction        VARCHAR(255) NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY ma_client_uuid_uq (client_uuid),
  KEY ma_session_idx (session_id),
  KEY ma_patient_idx (patient_id, administered_at),
  CONSTRAINT ma_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id) ON DELETE CASCADE,
  CONSTRAINT ma_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT ma_order_fk   FOREIGN KEY (order_id) REFERENCES medication_orders(id),
  CONSTRAINT ma_med_fk     FOREIGN KEY (medication_id) REFERENCES medication_refs(id),
  CONSTRAINT ma_giver_fk   FOREIGN KEY (given_by) REFERENCES staff(id),
  CONSTRAINT ma_witness_fk FOREIGN KEY (witnessed_by) REFERENCES staff(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Laboratory
-- ---------------------------------------------------------------------
CREATE TABLE lab_test_refs (
  code        VARCHAR(16) NOT NULL PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  unit        VARCHAR(20) NULL,
  ref_low     DECIMAL(12,4) NULL,
  ref_high    DECIMAL(12,4) NULL,
  target_low  DECIMAL(12,4) NULL,
  target_high DECIMAL(12,4) NULL,
  panel       VARCHAR(20) NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE lab_orders (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id   BIGINT UNSIGNED NOT NULL,
  ordered_on   DATE NOT NULL,
  panel        VARCHAR(20) NULL,
  ordered_by   BIGINT UNSIGNED NULL,
  status       VARCHAR(16) NOT NULL DEFAULT 'ordered',
  collected_at DATETIME(3) NULL,
  lab_name     VARCHAR(120) NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY lo_patient_idx (patient_id, ordered_on),
  CONSTRAINT lo_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT lo_staff_fk   FOREIGN KEY (ordered_by) REFERENCES staff(id),
  CONSTRAINT lo_status_ck  CHECK (status IN ('ordered','collected','resulted','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE lab_results (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NULL,
  patient_id    BIGINT UNSIGNED NOT NULL,
  test_code     VARCHAR(16) NOT NULL,
  value_num     DECIMAL(14,4) NULL,
  value_text    VARCHAR(255) NULL,
  unit          VARCHAR(20) NULL,
  specimen_date DATE NOT NULL,
  timing        VARCHAR(12) NULL,
  abnormal_flag VARCHAR(2) NULL,
  resulted_at   DATETIME(3) NULL,
  entered_by    BIGINT UNSIGNED NULL,
  source        VARCHAR(16) NOT NULL DEFAULT 'manual',
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY lr_uq (patient_id, test_code, specimen_date, timing),
  KEY lr_patient_idx (patient_id, test_code, specimen_date),
  CONSTRAINT lr_order_fk   FOREIGN KEY (order_id) REFERENCES lab_orders(id) ON DELETE CASCADE,
  CONSTRAINT lr_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT lr_test_fk    FOREIGN KEY (test_code) REFERENCES lab_test_refs(code),
  CONSTRAINT lr_timing_ck  CHECK (timing IS NULL OR timing IN ('pre_hd','post_hd','mid_week','non_hd_day')),
  CONSTRAINT lr_flag_ck    CHECK (abnormal_flag IS NULL OR abnormal_flag IN ('L','H','LL','HH','N')),
  CONSTRAINT lr_source_ck  CHECK (source IN ('manual','hl7','csv_import','api'))
) ENGINE=InnoDB;

CREATE TABLE hospitalisations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id    BIGINT UNSIGNED NOT NULL,
  admitted_on   DATE NOT NULL,
  discharged_on DATE NULL,
  facility_name VARCHAR(160) NULL,
  reason        VARCHAR(255) NULL,
  is_access_related TINYINT(1) NOT NULL DEFAULT 0,
  is_infection  TINYINT(1) NOT NULL DEFAULT 0,
  outcome       VARCHAR(120) NULL,
  recorded_by   BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY hosp_patient_idx (patient_id, admitted_on),
  CONSTRAINT hosp_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT hosp_dates_ck CHECK (discharged_on IS NULL OR discharged_on >= admitted_on)
) ENGINE=InnoDB;

CREATE TABLE vaccinations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id  BIGINT UNSIGNED NOT NULL,
  vaccine     VARCHAR(40) NOT NULL,
  dose_no     SMALLINT UNSIGNED NULL,
  given_on    DATE NOT NULL,
  lot_no      VARCHAR(40) NULL,
  site        VARCHAR(40) NULL,
  given_by    BIGINT UNSIGNED NULL,
  next_due_on DATE NULL,
  KEY vacc_patient_idx (patient_id, vaccine),
  CONSTRAINT vacc_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id)
) ENGINE=InnoDB;

-- =====================================================================
-- OPS
-- =====================================================================

CREATE TABLE machines (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_tag        VARCHAR(30) NOT NULL,
  manufacturer     VARCHAR(60) NULL,
  model            VARCHAR(60) NULL,
  serial_no        VARCHAR(60) NULL,
  software_version VARCHAR(30) NULL,
  commissioned_on  DATE NULL,
  warranty_until   DATE NULL,
  status           VARCHAR(20) NOT NULL DEFAULT 'in_service',
  home_station_id  INT UNSIGNED NULL,
  dedicated_cohort VARCHAR(10) NULL,                  -- NULL = general use
  total_run_hours  DECIMAL(10,1) NOT NULL DEFAULT 0,
  supports_online_clearance TINYINT(1) NOT NULL DEFAULT 0,
  supports_bvm     TINYINT(1) NOT NULL DEFAULT 0,
  data_export_mode VARCHAR(20) NULL,
  notes            TEXT NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY machines_asset_uq (asset_tag),
  UNIQUE KEY machines_serial_uq (serial_no),
  CONSTRAINT machines_station_fk FOREIGN KEY (home_station_id) REFERENCES stations(id),
  CONSTRAINT machines_status_ck CHECK (status IN ('in_service','standby','under_repair','quarantined','retired')),
  CONSTRAINT machines_cohort_ck CHECK (dedicated_cohort IS NULL OR dedicated_cohort IN ('clean','hbv','hcv')),
  CONSTRAINT machines_export_ck CHECK (data_export_mode IS NULL OR data_export_mode IN
    ('none','manual','serial','network_hl7','vendor_api'))
) ENGINE=InnoDB;

ALTER TABLE treatment_sessions
  ADD CONSTRAINT ts_machine_fk FOREIGN KEY (machine_id) REFERENCES machines(id);

CREATE TABLE machine_maintenance_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  machine_id       INT UNSIGNED NOT NULL,
  maintenance_type VARCHAR(20) NOT NULL,
  performed_on     DATE NOT NULL,
  run_hours_at     DECIMAL(10,1) NULL,
  description      TEXT NULL,
  parts_replaced   TEXT NULL,
  performed_by     VARCHAR(120) NULL,                 -- may be an external engineer
  performed_by_staff BIGINT UNSIGNED NULL,
  cost             DECIMAL(12,2) NULL,
  passed           TINYINT(1) NULL,
  next_due_on      DATE NULL,
  document_path    VARCHAR(255) NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY mm_machine_idx (machine_id, performed_on),
  KEY mm_due_idx (next_due_on),
  CONSTRAINT mm_machine_fk FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
  CONSTRAINT mm_type_ck CHECK (maintenance_type IN
    ('preventive','corrective','calibration','safety_test','decommission'))
) ENGINE=InnoDB;

CREATE TABLE machine_disinfection_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  machine_id    INT UNSIGNED NOT NULL,
  performed_at  DATETIME(3) NOT NULL,
  method        VARCHAR(20) NOT NULL,
  agent         VARCHAR(60) NULL,
  duration_min  SMALLINT UNSIGNED NULL,
  residual_test_done TINYINT(1) NULL,
  residual_test_result VARCHAR(12) NULL,
  performed_by  BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY md_machine_idx (machine_id, performed_at),
  CONSTRAINT md_machine_fk FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
  CONSTRAINT md_method_ck CHECK (method IN ('heat','chemical','heat_citric','peracetic','other')),
  CONSTRAINT md_residual_ck CHECK (residual_test_result IS NULL OR residual_test_result IN
    ('negative','positive','not_done'))
) ENGINE=InnoDB;

CREATE TABLE water_systems (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80) NOT NULL,
  manufacturer    VARCHAR(60) NULL,
  model           VARCHAR(60) NULL,
  serial_no       VARCHAR(60) NULL,
  commissioned_on DATE NULL,
  loop_type       VARCHAR(20) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT ws_loop_ck CHECK (loop_type IS NULL OR loop_type IN ('direct_feed','indirect_loop'))
) ENGINE=InnoDB;

CREATE TABLE water_daily_logs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  water_system_id     INT UNSIGNED NOT NULL,
  logged_at           DATETIME(3) NOT NULL,
  shift_id            INT UNSIGNED NULL,
  feed_pressure_psi   DECIMAL(6,2) NULL,
  product_pressure_psi DECIMAL(6,2) NULL,
  reject_pressure_psi DECIMAL(6,2) NULL,
  feed_conductivity_us DECIMAL(8,2) NULL,
  product_conductivity_us DECIMAL(8,2) NULL,
  rejection_pct       DECIMAL(5,2) NULL,
  temperature_c       DECIMAL(5,2) NULL,
  total_chlorine_ppm  DECIMAL(6,3) NULL,               -- action limit 0.1 ppm
  free_chlorine_ppm   DECIMAL(6,3) NULL,
  hardness_ppm        DECIMAL(6,2) NULL,
  ph                  DECIMAL(4,2) NULL,
  softener_salt_ok    TINYINT(1) NULL,
  carbon_tank_ok      TINYINT(1) NULL,
  action_taken        TEXT NULL,
  is_out_of_range     TINYINT(1) NOT NULL DEFAULT 0,
  logged_by           BIGINT UNSIGNED NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY wdl_system_idx (water_system_id, logged_at),
  KEY wdl_breach_idx (is_out_of_range, logged_at),
  CONSTRAINT wdl_system_fk FOREIGN KEY (water_system_id) REFERENCES water_systems(id),
  CONSTRAINT wdl_shift_fk  FOREIGN KEY (shift_id) REFERENCES shifts(id)
) ENGINE=InnoDB;

CREATE TABLE water_quality_tests (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  water_system_id INT UNSIGNED NOT NULL,
  sample_point    VARCHAR(60) NOT NULL,
  sampled_on      DATE NOT NULL,
  test_type       VARCHAR(20) NOT NULL,
  result_num      DECIMAL(14,4) NULL,
  unit            VARCHAR(20) NULL,
  limit_value     DECIMAL(14,4) NULL,
  action_level    DECIMAL(14,4) NULL,
  passed          TINYINT(1) NULL,
  lab_name        VARCHAR(120) NULL,
  document_path   VARCHAR(255) NULL,
  corrective_action TEXT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY wqt_system_idx (water_system_id, sampled_on),
  CONSTRAINT wqt_system_fk FOREIGN KEY (water_system_id) REFERENCES water_systems(id),
  CONSTRAINT wqt_type_ck CHECK (test_type IN ('microbiology','endotoxin','chemical_aami','chlorine','other'))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Inventory
-- ---------------------------------------------------------------------
CREATE TABLE items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sku             VARCHAR(40) NOT NULL,
  name            VARCHAR(160) NOT NULL,
  category        VARCHAR(24) NOT NULL,
  manufacturer    VARCHAR(80) NULL,
  unit_of_measure VARCHAR(20) NOT NULL DEFAULT 'piece',
  membrane        VARCHAR(60) NULL,
  surface_area_m2 DECIMAL(4,2) NULL,
  koa             DECIMAL(8,2) NULL,
  flux            VARCHAR(6) NULL,
  is_reusable     TINYINT(1) NOT NULL DEFAULT 0,
  max_reuse_count SMALLINT UNSIGNED NULL,
  reorder_level   DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_qty     DECIMAL(12,2) NOT NULL DEFAULT 0,
  default_cost    DECIMAL(12,2) NULL,
  medication_id   INT UNSIGNED NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY items_sku_uq (sku),
  KEY items_category_idx (category, is_active),
  CONSTRAINT items_med_fk FOREIGN KEY (medication_id) REFERENCES medication_refs(id),
  CONSTRAINT items_category_ck CHECK (category IN
    ('dialyzer','bloodline','fistula_needle','concentrate_acid','concentrate_bicarb',
     'saline','drug','disinfectant','dressing','catheter','consumable','other')),
  CONSTRAINT items_flux_ck CHECK (flux IS NULL OR flux IN ('low','high'))
) ENGINE=InnoDB;

ALTER TABLE hd_prescriptions
  ADD CONSTRAINT hd_rx_dialyzer_fk FOREIGN KEY (dialyzer_item_id) REFERENCES items(id);
ALTER TABLE treatment_sessions
  ADD CONSTRAINT ts_dialyzer_item_fk FOREIGN KEY (dialyzer_item_id) REFERENCES items(id);

CREATE TABLE suppliers (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(160) NOT NULL,
  contact   VARCHAR(120) NULL,
  phone     VARCHAR(40) NULL,
  email     VARCHAR(160) NULL,
  address   VARCHAR(255) NULL,
  terms     VARCHAR(120) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE stock_lots (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id      BIGINT UNSIGNED NOT NULL,
  lot_no       VARCHAR(60) NOT NULL,
  expiry_date  DATE NULL,
  supplier_id  INT UNSIGNED NULL,
  received_on  DATE NOT NULL,
  qty_received DECIMAL(12,2) NOT NULL,
  qty_on_hand  DECIMAL(12,2) NOT NULL,
  unit_cost    DECIMAL(12,2) NULL,
  invoice_no   VARCHAR(60) NULL,
  is_quarantined TINYINT(1) NOT NULL DEFAULT 0,       -- recall hold
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY sl_uq (item_id, lot_no, received_on),
  KEY sl_expiry_idx (item_id, expiry_date),
  CONSTRAINT sl_item_fk     FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT sl_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT sl_qty_ck CHECK (qty_on_hand >= 0)
) ENGINE=InnoDB;

ALTER TABLE medication_administrations
  ADD CONSTRAINT ma_lot_fk FOREIGN KEY (lot_id) REFERENCES stock_lots(id);
ALTER TABLE treatment_sessions
  ADD CONSTRAINT ts_bloodline_lot_fk FOREIGN KEY (bloodline_lot_id) REFERENCES stock_lots(id);

CREATE TABLE stock_transactions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lot_id       BIGINT UNSIGNED NOT NULL,
  item_id      BIGINT UNSIGNED NOT NULL,
  move_type    VARCHAR(24) NOT NULL,
  qty          DECIMAL(12,2) NOT NULL,                -- signed: + in, - out
  session_id   BIGINT UNSIGNED NULL,
  patient_id   BIGINT UNSIGNED NULL,
  client_uuid  CHAR(26) NULL,
  reason       VARCHAR(255) NULL,
  occurred_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  performed_by BIGINT UNSIGNED NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY st_client_uuid_uq (client_uuid),
  KEY st_lot_idx (lot_id, occurred_at),
  KEY st_session_idx (session_id),
  CONSTRAINT st_lot_fk     FOREIGN KEY (lot_id) REFERENCES stock_lots(id),
  CONSTRAINT st_item_fk    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT st_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id),
  CONSTRAINT st_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT st_move_ck CHECK (move_type IN
    ('receipt','issue_to_session','issue_to_ward','return','adjustment',
     'expiry_writeoff','damage','transfer'))
) ENGINE=InnoDB;

-- Dialyzer reuse: one row per physical labelled dialyzer, owned by one patient.
CREATE TABLE dialyzer_units (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id        BIGINT UNSIGNED NOT NULL,
  lot_id         BIGINT UNSIGNED NULL,
  label_code     VARCHAR(40) NOT NULL,                -- barcode
  patient_id     BIGINT UNSIGNED NOT NULL,
  first_used_on  DATE NULL,
  use_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  initial_tcv_ml DECIMAL(6,1) NULL,
  current_tcv_ml DECIMAL(6,1) NULL,
  tcv_pct        DECIMAL(5,2) GENERATED ALWAYS AS (
                   CASE WHEN initial_tcv_ml > 0
                        THEN ROUND(current_tcv_ml / initial_tcv_ml * 100, 2) END) STORED,
  status         VARCHAR(16) NOT NULL DEFAULT 'active',
  discarded_on   DATE NULL,
  discard_reason VARCHAR(255) NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY du_label_uq (label_code),
  KEY du_patient_idx (patient_id, status),
  CONSTRAINT du_item_fk    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT du_lot_fk     FOREIGN KEY (lot_id) REFERENCES stock_lots(id),
  CONSTRAINT du_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT du_status_ck CHECK (status IN ('active','discarded','quarantined','failed_test'))
) ENGINE=InnoDB;

ALTER TABLE treatment_sessions
  ADD CONSTRAINT ts_dialyzer_unit_fk FOREIGN KEY (dialyzer_unit_id) REFERENCES dialyzer_units(id);

CREATE TABLE dialyzer_reprocess_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dialyzer_unit_id BIGINT UNSIGNED NOT NULL,
  session_id       BIGINT UNSIGNED NULL,
  reprocessed_at   DATETIME(3) NOT NULL,
  use_number       SMALLINT UNSIGNED NOT NULL,
  method           VARCHAR(12) NULL,
  germicide        VARCHAR(40) NULL,
  germicide_conc   VARCHAR(20) NULL,
  tcv_ml           DECIMAL(6,1) NULL,
  tcv_pct_of_initial DECIMAL(5,2) NULL,
  pressure_test_passed TINYINT(1) NULL,
  fibre_bundle_ok  TINYINT(1) NULL,
  visual_ok        TINYINT(1) NULL,
  residual_test_passed TINYINT(1) NULL,
  accepted         TINYINT(1) NOT NULL,
  reject_reason    VARCHAR(255) NULL,
  performed_by     BIGINT UNSIGNED NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY drl_uq (dialyzer_unit_id, use_number),
  CONSTRAINT drl_unit_fk    FOREIGN KEY (dialyzer_unit_id) REFERENCES dialyzer_units(id) ON DELETE CASCADE,
  CONSTRAINT drl_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id),
  CONSTRAINT drl_method_ck CHECK (method IS NULL OR method IN ('manual','automated'))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Scheduling
-- ---------------------------------------------------------------------
CREATE TABLE standing_schedules (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id     BIGINT UNSIGNED NOT NULL,
  shift_id       INT UNSIGNED NOT NULL,
  station_id     INT UNSIGNED NULL,
  weekday_mask   TINYINT UNSIGNED NOT NULL,           -- bit 0 = Monday
  effective_from DATE NOT NULL,
  effective_to   DATE NULL,
  effective_to_x DATE GENERATED ALWAYS AS (COALESCE(effective_to, '9999-12-31')) STORED,
  notes          VARCHAR(255) NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by     BIGINT UNSIGNED NULL,
  KEY ss_patient_idx (patient_id, effective_from, effective_to_x),
  CONSTRAINT ss_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT ss_shift_fk   FOREIGN KEY (shift_id) REFERENCES shifts(id),
  CONSTRAINT ss_station_fk FOREIGN KEY (station_id) REFERENCES stations(id),
  CONSTRAINT ss_mask_ck    CHECK (weekday_mask BETWEEN 1 AND 127),
  CONSTRAINT ss_period_ck  CHECK (effective_to IS NULL OR effective_to > effective_from)
) ENGINE=InnoDB;

CREATE TABLE schedule_exceptions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id     BIGINT UNSIGNED NOT NULL,
  exception_date DATE NOT NULL,
  kind           VARCHAR(16) NOT NULL,
  new_shift_id   INT UNSIGNED NULL,
  new_station_id INT UNSIGNED NULL,
  reason         VARCHAR(255) NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY se_uq (patient_id, exception_date, kind),
  CONSTRAINT sx_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT sx_shift_fk   FOREIGN KEY (new_shift_id) REFERENCES shifts(id),
  CONSTRAINT sx_station_fk FOREIGN KEY (new_station_id) REFERENCES stations(id),
  CONSTRAINT sx_kind_ck CHECK (kind IN ('cancel','reschedule','extra_session','holiday'))
) ENGINE=InnoDB;

CREATE TABLE staff_rosters (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  staff_id   BIGINT UNSIGNED NOT NULL,
  duty_date  DATE NOT NULL,
  shift_id   INT UNSIGNED NOT NULL,
  role_code  VARCHAR(40) NULL,
  is_on_call TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY sr_uq (staff_id, duty_date, shift_id),
  KEY sr_date_idx (duty_date, shift_id),
  CONSTRAINT sr_staff_fk FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  CONSTRAINT sr_shift_fk FOREIGN KEY (shift_id) REFERENCES shifts(id),
  CONSTRAINT sr_role_fk  FOREIGN KEY (role_code) REFERENCES roles(code)
) ENGINE=InnoDB;

-- Replaces Postgres `station_ids integer[]`
CREATE TABLE staff_roster_stations (
  roster_id  BIGINT UNSIGNED NOT NULL,
  station_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (roster_id, station_id),
  CONSTRAINT srs_roster_fk  FOREIGN KEY (roster_id) REFERENCES staff_rosters(id) ON DELETE CASCADE,
  CONSTRAINT srs_station_fk FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB;

-- =====================================================================
-- BILLING
-- =====================================================================

CREATE TABLE payers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30) NOT NULL,
  name         VARCHAR(160) NOT NULL,
  kind         VARCHAR(24) NOT NULL,
  country_code CHAR(2) NULL,
  claim_format VARCHAR(30) NULL,
  portal_url   VARCHAR(255) NULL,
  contact      VARCHAR(160) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY payers_code_uq (code),
  CONSTRAINT payers_kind_ck CHECK (kind IN
    ('national_insurance','private_insurance','hmo','corporate','charity',
     'government_subsidy','self_pay'))
) ENGINE=InnoDB;

CREATE TABLE patient_coverages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id      BIGINT UNSIGNED NOT NULL,
  payer_id        INT UNSIGNED NOT NULL,
  member_no       VARCHAR(60) NULL,
  member_category VARCHAR(30) NULL,
  effective_from  DATE NOT NULL,
  effective_to    DATE NULL,
  priority        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  card_path       VARCHAR(255) NULL,
  verified_on     DATE NULL,
  verified_by     BIGINT UNSIGNED NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY pc_patient_idx (patient_id, priority),
  CONSTRAINT pc_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT pc_payer_fk   FOREIGN KEY (payer_id) REFERENCES payers(id)
) ENGINE=InnoDB;

-- Benefit rules are DATA. The PhilHealth HD rate moved P2,600 -> P4,000 ->
-- P6,350 and the cap 90 -> 156 sessions inside two years. Never hardcode.
CREATE TABLE benefit_programs (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payer_id            INT UNSIGNED NOT NULL,
  code                VARCHAR(40) NOT NULL,
  name                VARCHAR(160) NOT NULL,
  modality            VARCHAR(12) NULL,
  sessions_per_period INT UNSIGNED NULL,
  period_kind         VARCHAR(20) NOT NULL DEFAULT 'calendar_year',
  case_rate           DECIMAL(12,2) NULL,
  facility_fee        DECIMAL(12,2) NULL,
  professional_fee    DECIMAL(12,2) NULL,
  currency            CHAR(3) NOT NULL DEFAULT 'PHP',
  no_balance_billing  TINYINT(1) NOT NULL DEFAULT 0,
  effective_from      DATE NOT NULL,
  effective_to        DATE NULL,
  circular_ref        VARCHAR(120) NULL,
  notes               VARCHAR(255) NULL,
  UNIQUE KEY bp_code_uq (code),
  CONSTRAINT bp_payer_fk FOREIGN KEY (payer_id) REFERENCES payers(id),
  CONSTRAINT bp_period_ck CHECK (period_kind IN ('calendar_year','rolling_year','month','lifetime'))
) ENGINE=InnoDB;

CREATE TABLE benefit_periods (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patient_id        BIGINT UNSIGNED NOT NULL,
  program_id        INT UNSIGNED NOT NULL,
  period_start      DATE NOT NULL,
  period_end        DATE NOT NULL,
  sessions_allotted INT UNSIGNED NOT NULL,
  sessions_reserved INT UNSIGNED NOT NULL DEFAULT 0,
  notes             VARCHAR(255) NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY bper_uq (patient_id, program_id, period_start),
  CONSTRAINT bper_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT bper_program_fk FOREIGN KEY (program_id) REFERENCES benefit_programs(id),
  CONSTRAINT bper_dates_ck CHECK (period_end > period_start)
) ENGINE=InnoDB;

CREATE TABLE service_items (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(30) NOT NULL,
  name       VARCHAR(160) NOT NULL,
  category   VARCHAR(30) NULL,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_taxable TINYINT(1) NOT NULL DEFAULT 0,
  item_id    BIGINT UNSIGNED NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY si_code_uq (code),
  CONSTRAINT si_item_fk FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_no    VARCHAR(40) NOT NULL,
  patient_id    BIGINT UNSIGNED NOT NULL,
  issued_on     DATE NOT NULL,
  due_on        DATE NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'draft',
  currency      CHAR(3) NOT NULL DEFAULT 'PHP',
  subtotal      DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount      DECIMAL(12,2) NOT NULL DEFAULT 0,     -- senior / PWD discount
  tax           DECIMAL(12,2) NOT NULL DEFAULT 0,
  payer_covered DECIMAL(12,2) NOT NULL DEFAULT 0,
  patient_due   DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid   DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance       DECIMAL(13,2) GENERATED ALWAYS AS (patient_due - amount_paid) STORED,
  notes         TEXT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY inv_no_uq (invoice_no),
  KEY inv_patient_idx (patient_id, issued_on),
  KEY inv_status_idx (status),
  CONSTRAINT inv_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT inv_status_ck CHECK (status IN ('draft','issued','partially_paid','paid','void','written_off'))
) ENGINE=InnoDB;

CREATE TABLE invoice_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id      BIGINT UNSIGNED NOT NULL,
  session_id      BIGINT UNSIGNED NULL,
  service_item_id INT UNSIGNED NULL,
  description     VARCHAR(255) NOT NULL,
  qty             DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit_price      DECIMAL(12,2) NOT NULL,
  discount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total      DECIMAL(14,2) GENERATED ALWAYS AS (qty * unit_price - discount) STORED,
  payer_id        INT UNSIGNED NULL,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  KEY il_invoice_idx (invoice_id),
  CONSTRAINT il_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT il_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id),
  CONSTRAINT il_service_fk FOREIGN KEY (service_item_id) REFERENCES service_items(id),
  CONSTRAINT il_payer_fk   FOREIGN KEY (payer_id) REFERENCES payers(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id   BIGINT UNSIGNED NOT NULL,
  paid_on      DATE NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  method       VARCHAR(24) NOT NULL,
  reference_no VARCHAR(60) NULL,
  payer_id     INT UNSIGNED NULL,
  received_by  BIGINT UNSIGNED NULL,
  notes        VARCHAR(255) NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY pay_invoice_idx (invoice_id),
  CONSTRAINT pay_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT pay_payer_fk   FOREIGN KEY (payer_id) REFERENCES payers(id),
  CONSTRAINT pay_amount_ck  CHECK (amount <> 0),
  CONSTRAINT pay_method_ck  CHECK (method IN
    ('cash','card','bank_transfer','ewallet','cheque','payer_remittance','adjustment'))
) ENGINE=InnoDB;

CREATE TABLE claims (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_no          VARCHAR(40) NULL,
  patient_id        BIGINT UNSIGNED NOT NULL,
  payer_id          INT UNSIGNED NOT NULL,
  program_id        INT UNSIGNED NULL,
  benefit_period_id BIGINT UNSIGNED NULL,
  coverage_id       BIGINT UNSIGNED NULL,
  service_from      DATE NOT NULL,
  service_to        DATE NOT NULL,
  session_count     INT UNSIGNED NOT NULL DEFAULT 1,
  amount_claimed    DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_approved   DECIMAL(12,2) NULL,
  amount_paid       DECIMAL(12,2) NULL,
  status            VARCHAR(20) NOT NULL DEFAULT 'draft',
  submitted_at      DATETIME(3) NULL,
  acknowledged_at   DATETIME(3) NULL,
  paid_at           DATETIME(3) NULL,
  external_ref      VARCHAR(80) NULL,
  denial_code       VARCHAR(40) NULL,
  denial_reason     VARCHAR(255) NULL,
  resubmission_of   BIGINT UNSIGNED NULL,
  payload_path      VARCHAR(255) NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by        BIGINT UNSIGNED NULL,
  updated_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY claims_no_uq (claim_no),
  KEY claims_status_idx (status, submitted_at),
  KEY claims_patient_idx (patient_id, service_from),
  CONSTRAINT claims_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT claims_payer_fk   FOREIGN KEY (payer_id) REFERENCES payers(id),
  CONSTRAINT claims_program_fk FOREIGN KEY (program_id) REFERENCES benefit_programs(id),
  CONSTRAINT claims_period_fk  FOREIGN KEY (benefit_period_id) REFERENCES benefit_periods(id),
  CONSTRAINT claims_coverage_fk FOREIGN KEY (coverage_id) REFERENCES patient_coverages(id),
  CONSTRAINT claims_resub_fk   FOREIGN KEY (resubmission_of) REFERENCES claims(id),
  CONSTRAINT claims_dates_ck CHECK (service_to >= service_from),
  CONSTRAINT claims_status_ck CHECK (status IN
    ('draft','ready','submitted','acknowledged','in_process','approved',
     'partially_paid','paid','denied','returned','resubmitted','void'))
) ENGINE=InnoDB;

ALTER TABLE treatment_sessions
  ADD CONSTRAINT ts_claim_fk FOREIGN KEY (benefit_claim_id) REFERENCES claims(id);

-- A session may appear in at most one non-void claim: the PK on session_id
-- is what makes double-billing structurally impossible.
CREATE TABLE claim_sessions (
  session_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  claim_id       BIGINT UNSIGNED NOT NULL,
  benefit_seq_no INT UNSIGNED NULL,                   -- "session 47 of 156"
  amount         DECIMAL(12,2) NULL,
  KEY cs_claim_idx (claim_id),
  CONSTRAINT cs_claim_fk   FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE,
  CONSTRAINT cs_session_fk FOREIGN KEY (session_id) REFERENCES treatment_sessions(id)
) ENGINE=InnoDB;

CREATE TABLE claim_status_histories (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id     BIGINT UNSIGNED NOT NULL,
  status       VARCHAR(20) NOT NULL,
  changed_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  changed_by   BIGINT UNSIGNED NULL,
  remarks      VARCHAR(255) NULL,
  raw_response JSON NULL,
  KEY csh_claim_idx (claim_id, changed_at),
  CONSTRAINT csh_claim_fk FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE claim_attachments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id    BIGINT UNSIGNED NOT NULL,
  doc_type    VARCHAR(30) NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  checksum    CHAR(64) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  uploaded_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ca_claim_idx (claim_id),
  CONSTRAINT ca_claim_fk FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- AUDIT
-- =====================================================================
-- Written by Laravel model observers, NOT by database triggers: MySQL
-- cannot serialise a row to JSON inside a trigger. The corollary is that
-- no human may hold a write-capable MySQL account.

CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  occurred_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  actor_id     BIGINT UNSIGNED NULL,
  actor_name   VARCHAR(160) NULL,
  action       VARCHAR(12) NOT NULL,
  auditable_type VARCHAR(120) NULL,                   -- Eloquent morph type
  auditable_id BIGINT UNSIGNED NULL,
  -- JSON here is correct: these are queried with JSON_EXTRACT during audits,
  -- and semantic equality is what matters, not byte equality.
  before_data  JSON NULL,
  after_data   JSON NULL,
  changed_cols JSON NULL,
  ip_address   VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  reason       VARCHAR(255) NULL,
  KEY al_time_idx (occurred_at),
  KEY al_target_idx (auditable_type, auditable_id),
  KEY al_actor_idx (actor_id, occurred_at),
  CONSTRAINT al_action_ck CHECK (action IN
    ('INSERT','UPDATE','DELETE','LOGIN','LOGOUT','EXPORT','PRINT','VIEW'))
) ENGINE=InnoDB;

-- Who VIEWED which chart. Required by PH Data Privacy Act / MY PDPA /
-- ID PDP Law, and separate from the change trail above.
CREATE TABLE record_access_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  accessed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  actor_id    BIGINT UNSIGNED NULL,
  patient_id  BIGINT UNSIGNED NULL,
  context     VARCHAR(40) NULL,
  ip_address  VARCHAR(45) NULL,
  KEY ral_patient_idx (patient_id, accessed_at),
  KEY ral_actor_idx (actor_id, accessed_at),
  CONSTRAINT ral_actor_fk   FOREIGN KEY (actor_id) REFERENCES staff(id),
  CONSTRAINT ral_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id)
) ENGINE=InnoDB;

CREATE TABLE login_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  occurred_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  staff_id       BIGINT UNSIGNED NULL,
  username       VARCHAR(160) NULL,
  success        TINYINT(1) NOT NULL,
  failure_reason VARCHAR(120) NULL,
  ip_address     VARCHAR(45) NULL,
  user_agent     VARCHAR(255) NULL,
  KEY le_time_idx (occurred_at),
  KEY le_staff_idx (staff_id, occurred_at),
  CONSTRAINT le_staff_fk FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB;

-- Offline sync ledger: one row per accepted batch from a device.
-- Lets a tablet ask "did you get batch X?" after a network drop.
CREATE TABLE sync_batches (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_uuid     CHAR(26) NOT NULL,
  device_id      VARCHAR(64) NOT NULL,
  staff_id       BIGINT UNSIGNED NULL,
  received_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  operation_count INT UNSIGNED NOT NULL DEFAULT 0,
  applied_count  INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
  -- LONGTEXT, deliberately NOT JSON. MySQL normalises JSON columns: it
  -- reorders object keys and rewrites numeric literals. A replayed sync
  -- batch must return the byte-identical response the tablet already
  -- reconciled against, so this payload is stored verbatim.
  result         LONGTEXT NULL,
  UNIQUE KEY sb_uuid_uq (batch_uuid),
  KEY sb_device_idx (device_id, received_at),
  CONSTRAINT sb_staff_fk FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB;

-- =====================================================================
-- TRIGGERS
-- =====================================================================
-- These are BACKSTOPS. The authoritative rules live in Laravel services
-- (they can produce good error messages and audit entries). The triggers
-- exist so that a bug, a console command or a rogue script cannot violate
-- an invariant that has patient-safety or billing consequences.

DELIMITER $$

-- ---- 1. Non-overlapping prescription periods --------------------------
-- Replaces the Postgres `EXCLUDE USING gist (patient_id WITH =,
-- effective_period WITH &&)`. Half-open interval: [from, to).
CREATE TRIGGER hd_prescriptions_bi BEFORE INSERT ON hd_prescriptions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1 FROM hd_prescriptions p
     WHERE p.patient_id = NEW.patient_id
       AND p.effective_from < COALESCE(NEW.effective_to, '9999-12-31')
       AND NEW.effective_from < p.effective_to_x
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Overlapping prescription period for this patient';
  END IF;
END$$

CREATE TRIGGER hd_prescriptions_bu BEFORE UPDATE ON hd_prescriptions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1 FROM hd_prescriptions p
     WHERE p.patient_id = NEW.patient_id
       AND p.id <> NEW.id
       AND p.effective_from < COALESCE(NEW.effective_to, '9999-12-31')
       AND NEW.effective_from < p.effective_to_x
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Overlapping prescription period for this patient';
  END IF;
END$$

-- ---- 2. Non-overlapping standing schedules ---------------------------
CREATE TRIGGER standing_schedules_bi BEFORE INSERT ON standing_schedules
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1 FROM standing_schedules s
     WHERE s.patient_id = NEW.patient_id
       AND s.effective_from < COALESCE(NEW.effective_to, '9999-12-31')
       AND NEW.effective_from < s.effective_to_x
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Overlapping standing schedule for this patient';
  END IF;
END$$

-- ---- 3. A signed session is immutable --------------------------------
-- Mirrors clinical.guard_locked_session() from the Postgres design.
-- Administrative columns stay writable because a claim is attached AFTER
-- sign-off. A real correction sets @allow_amendment = 1 for the
-- transaction; only the amendment workflow in Laravel does that.
CREATE TRIGGER treatment_sessions_bu BEFORE UPDATE ON treatment_sessions
FOR EACH ROW
BEGIN
  DECLARE old_fp CHAR(32);
  DECLARE new_fp CHAR(32);

  IF OLD.locked_at IS NOT NULL AND COALESCE(@allow_amendment, 0) <> 1 THEN
    SET old_fp = MD5(CONCAT_WS('|',
      OLD.patient_id, OLD.session_date, OLD.shift_id, OLD.station_id, OLD.machine_id,
      OLD.prescription_id, OLD.status, OLD.modality,
      OLD.pre_weight_kg, OLD.dry_weight_kg, OLD.pre_bp_sys, OLD.pre_bp_dia,
      OLD.pre_pulse, OLD.pre_temp_c, OLD.pre_resp_rate, OLD.pre_spo2_pct,
      OLD.pre_glucose_mmol, OLD.pre_notes,
      OLD.dialyzer_item_id, OLD.dialyzer_unit_id, OLD.dialyzer_use_no,
      OLD.bloodline_lot_id, OLD.vascular_access_id, OLD.needle_gauge,
      OLD.cannulation_attempts, OLD.planned_duration_min, OLD.planned_uf_ml,
      OLD.blood_flow_set_ml_min, OLD.dialysate_flow_ml_min,
      OLD.dialysate_na_mmol, OLD.dialysate_k_mmol, OLD.dialysate_ca_mmol,
      OLD.dialysate_hco3_mmol, OLD.dialysate_temp_c,
      OLD.anticoagulant, OLD.ac_loading_dose, OLD.ac_maintenance_hr,
      OLD.ac_total_given, OLD.priming_volume_ml,
      OLD.started_at, OLD.ended_at, OLD.termination_reason, OLD.termination_notes,
      OLD.post_weight_kg, OLD.net_uf_ml, OLD.total_intake_ml,
      OLD.post_bp_sys, OLD.post_bp_dia, OLD.post_pulse, OLD.post_temp_c,
      OLD.post_resp_rate, OLD.post_spo2_pct, OLD.blood_volume_processed_l,
      OLD.ktv, OLD.ktv_method, OLD.urr_pct, OLD.pre_bun_mmol, OLD.post_bun_mmol,
      OLD.ambulation, OLD.discharge_condition, OLD.discharged_at, OLD.discharge_notes,
      OLD.primary_nurse_id, OLD.assisting_staff_id, OLD.technician_id, OLD.physician_id,
      OLD.nurse_signed_by, OLD.nurse_signed_at,
      OLD.physician_signed_by, OLD.physician_signed_at, OLD.locked_at));

    SET new_fp = MD5(CONCAT_WS('|',
      NEW.patient_id, NEW.session_date, NEW.shift_id, NEW.station_id, NEW.machine_id,
      NEW.prescription_id, NEW.status, NEW.modality,
      NEW.pre_weight_kg, NEW.dry_weight_kg, NEW.pre_bp_sys, NEW.pre_bp_dia,
      NEW.pre_pulse, NEW.pre_temp_c, NEW.pre_resp_rate, NEW.pre_spo2_pct,
      NEW.pre_glucose_mmol, NEW.pre_notes,
      NEW.dialyzer_item_id, NEW.dialyzer_unit_id, NEW.dialyzer_use_no,
      NEW.bloodline_lot_id, NEW.vascular_access_id, NEW.needle_gauge,
      NEW.cannulation_attempts, NEW.planned_duration_min, NEW.planned_uf_ml,
      NEW.blood_flow_set_ml_min, NEW.dialysate_flow_ml_min,
      NEW.dialysate_na_mmol, NEW.dialysate_k_mmol, NEW.dialysate_ca_mmol,
      NEW.dialysate_hco3_mmol, NEW.dialysate_temp_c,
      NEW.anticoagulant, NEW.ac_loading_dose, NEW.ac_maintenance_hr,
      NEW.ac_total_given, NEW.priming_volume_ml,
      NEW.started_at, NEW.ended_at, NEW.termination_reason, NEW.termination_notes,
      NEW.post_weight_kg, NEW.net_uf_ml, NEW.total_intake_ml,
      NEW.post_bp_sys, NEW.post_bp_dia, NEW.post_pulse, NEW.post_temp_c,
      NEW.post_resp_rate, NEW.post_spo2_pct, NEW.blood_volume_processed_l,
      NEW.ktv, NEW.ktv_method, NEW.urr_pct, NEW.pre_bun_mmol, NEW.post_bun_mmol,
      NEW.ambulation, NEW.discharge_condition, NEW.discharged_at, NEW.discharge_notes,
      NEW.primary_nurse_id, NEW.assisting_staff_id, NEW.technician_id, NEW.physician_id,
      NEW.nurse_signed_by, NEW.nurse_signed_at,
      NEW.physician_signed_by, NEW.physician_signed_at, NEW.locked_at));

    IF old_fp <> new_fp THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Session is locked. Use the amendment workflow instead of editing.';
    END IF;
  END IF;
END$$

-- ---- 4. Stock movements keep the lot balance honest ------------------
CREATE TRIGGER stock_transactions_ai AFTER INSERT ON stock_transactions
FOR EACH ROW
BEGIN
  UPDATE stock_lots SET qty_on_hand = qty_on_hand + NEW.qty WHERE id = NEW.lot_id;
END$$

-- ---- 5. High-alert medication requires a witness ---------------------
CREATE TRIGGER medication_administrations_bi BEFORE INSERT ON medication_administrations
FOR EACH ROW
BEGIN
  IF NEW.not_given = 0
     AND EXISTS (SELECT 1 FROM medication_refs m
                  WHERE m.id = NEW.medication_id AND m.is_high_alert = 1)
     AND NEW.witnessed_by IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'High-alert medication requires a witness';
  END IF;
END$$

DELIMITER ;

-- =====================================================================
-- VIEWS
-- =====================================================================
-- Postgres DISTINCT ON has no MySQL equivalent; ROW_NUMBER() does the job.
-- MySQL merges simple views but materialises anything with an aggregate or
-- window function into a temporary table, so predicates do NOT push down.
-- Rule of thumb applied here:
--   * per-row lookup views  -> real views (cheap, merged)
--   * heavy monthly rollups -> nightly summary tables (see the design doc),
--     with the view kept only as the definition of record.

-- Latest result per serology marker
CREATE OR REPLACE VIEW v_patient_serology_current AS
SELECT patient_id, marker, result, titre, specimen_date
FROM (
  SELECT s.*, ROW_NUMBER() OVER (PARTITION BY s.patient_id, s.marker
                                 ORDER BY s.specimen_date DESC, s.id DESC) rn
  FROM serology_results s
) x
WHERE rn = 1;

-- Derived infection-control cohort. HBV wins over HCV.
CREATE OR REPLACE VIEW v_patient_cohort AS
SELECT p.id AS patient_id,
       CASE
         WHEN MAX(s.marker = 'hbsag' AND s.result = 'reactive') = 1 THEN 'hbv'
         WHEN MAX(s.marker IN ('anti_hcv','hcv_rna') AND s.result = 'reactive') = 1 THEN 'hcv'
         ELSE 'clean'
       END AS cohort,
       MAX(s.marker = 'hiv' AND s.result = 'reactive') = 1 AS hiv_reactive,
       MAX(s.specimen_date) AS last_serology_date
FROM patients p
LEFT JOIN v_patient_serology_current s ON s.patient_id = p.id
GROUP BY p.id;

-- Prescription in force today
CREATE OR REPLACE VIEW v_current_prescription AS
SELECT * FROM (
  SELECT r.*, ROW_NUMBER() OVER (PARTITION BY r.patient_id
                                 ORDER BY r.effective_from DESC, r.id DESC) rn
  FROM hd_prescriptions r
  WHERE r.effective_from <= CURDATE() AND CURDATE() < r.effective_to_x
) x WHERE rn = 1;

-- Dry weight in force today
CREATE OR REPLACE VIEW v_current_dry_weight AS
SELECT patient_id, weight_kg, effective_from FROM (
  SELECT d.*, ROW_NUMBER() OVER (PARTITION BY d.patient_id
                                 ORDER BY d.effective_from DESC, d.id DESC) rn
  FROM dry_weights d WHERE d.effective_from <= CURDATE()
) x WHERE rn = 1;

-- Today's board: who is in which chair, on which machine
CREATE OR REPLACE VIEW v_daily_board AS
SELECT s.id AS session_id, s.public_id, s.session_date,
       sh.code AS shift_code, st.code AS station_code, m.asset_tag AS machine,
       p.mrn, p.full_name, c.cohort,
       s.status, s.started_at, s.ended_at,
       s.pre_weight_kg, s.dry_weight_kg, s.idwg_kg,
       s.planned_uf_ml, s.net_uf_ml,
       n.full_name AS primary_nurse
FROM treatment_sessions s
JOIN patients p ON p.id = s.patient_id
LEFT JOIN shifts   sh ON sh.id = s.shift_id
LEFT JOIN stations st ON st.id = s.station_id
LEFT JOIN machines m  ON m.id  = s.machine_id
LEFT JOIN staff    n  ON n.id  = s.primary_nurse_id
LEFT JOIN v_patient_cohort c ON c.patient_id = p.id;

-- Infection-control audit: anyone seated where their cohort is not allowed.
-- A station with no station_cohorts rows accepts any cohort.
CREATE OR REPLACE VIEW v_cohort_violation AS
SELECT s.id AS session_id, s.session_date, p.mrn, p.full_name,
       c.cohort, st.code AS station_code, m.asset_tag, m.dedicated_cohort
FROM treatment_sessions s
JOIN patients p ON p.id = s.patient_id
JOIN v_patient_cohort c ON c.patient_id = p.id
LEFT JOIN stations st ON st.id = s.station_id
LEFT JOIN machines m  ON m.id  = s.machine_id
WHERE s.status NOT IN ('cancelled','missed')
  AND (
    ( st.id IS NOT NULL
      AND EXISTS (SELECT 1 FROM station_cohorts sc WHERE sc.station_id = st.id)
      AND NOT EXISTS (SELECT 1 FROM station_cohorts sc
                       WHERE sc.station_id = st.id AND sc.cohort = c.cohort) )
    OR ( m.dedicated_cohort IS NOT NULL AND m.dedicated_cohort <> c.cohort )
  );

-- Monthly adequacy / quality. The LATERAL subquery is deliberate: joining
-- session_events directly would multiply each session row by its event
-- count and inflate every aggregate.
CREATE OR REPLACE VIEW v_monthly_quality AS
SELECT s.patient_id,
       DATE_FORMAT(s.session_date, '%Y-%m-01') AS month,
       COUNT(*)                                             AS sessions,
       SUM(s.status = 'completed')                          AS completed,
       SUM(s.status = 'missed')                             AS missed,
       SUM(s.termination_reason IS NOT NULL
           AND s.termination_reason <> 'completed_as_prescribed') AS shortened,
       ROUND(AVG(s.ktv), 2)                                 AS avg_ktv,
       ROUND(AVG(s.urr_pct), 1)                             AS avg_urr,
       ROUND(AVG(s.idwg_kg), 2)                             AS avg_idwg_kg,
       ROUND(AVG(s.actual_duration_min), 0)                 AS avg_duration_min,
       ROUND(AVG(s.pre_bp_sys), 0)                          AS avg_pre_sbp,
       SUM(ev.had_hypotension = 1)                          AS sessions_with_hypotension,
       SUM(ev.had_reportable = 1)                           AS sessions_with_reportable_event
FROM treatment_sessions s
LEFT JOIN LATERAL (
  SELECT MAX(e.event_code = 'hypotension') AS had_hypotension,
         MAX(r.is_reportable = 1)          AS had_reportable
  FROM session_events e
  JOIN event_refs r ON r.code = e.event_code
  WHERE e.session_id = s.id
) ev ON TRUE
GROUP BY s.patient_id, DATE_FORMAT(s.session_date, '%Y-%m-01');

-- "PhilHealth: 47 of 156 used, 109 left"
CREATE OR REPLACE VIEW v_benefit_utilisation AS
SELECT bp.id AS benefit_period_id, bp.patient_id, p.mrn, p.full_name,
       prog.code AS program_code, prog.name AS program_name,
       bp.period_start, bp.period_end, bp.sessions_allotted,
       COUNT(cs.session_id)                        AS sessions_claimed,
       bp.sessions_allotted - COUNT(cs.session_id) AS sessions_remaining,
       COALESCE(SUM(cs.amount), 0)                 AS amount_claimed
FROM benefit_periods bp
JOIN patients p ON p.id = bp.patient_id
JOIN benefit_programs prog ON prog.id = bp.program_id
LEFT JOIN claims c ON c.benefit_period_id = bp.id AND c.status <> 'void'
LEFT JOIN claim_sessions cs ON cs.claim_id = c.id
GROUP BY bp.id, bp.patient_id, p.mrn, p.full_name, prog.code, prog.name,
         bp.period_start, bp.period_end, bp.sessions_allotted;

CREATE OR REPLACE VIEW v_station_utilisation AS
SELECT st.code AS station_code, s.session_date, sh.code AS shift_code,
       SUM(s.status = 'completed')                                       AS completed_sessions,
       SUM(CASE WHEN s.status = 'completed' THEN s.actual_duration_min END) AS total_minutes
FROM stations st
LEFT JOIN treatment_sessions s ON s.station_id = st.id
LEFT JOIN shifts sh ON sh.id = s.shift_id
GROUP BY st.code, s.session_date, sh.code;

CREATE OR REPLACE VIEW v_dialyzer_status AS
SELECT du.id, du.label_code, p.mrn, p.full_name, i.name AS dialyzer,
       du.use_count, i.max_reuse_count, du.tcv_pct, du.status,
       CASE WHEN du.tcv_pct < 80 THEN 'discard_tcv'
            WHEN i.max_reuse_count IS NOT NULL AND du.use_count >= i.max_reuse_count THEN 'discard_count'
            WHEN i.max_reuse_count IS NOT NULL AND du.use_count >= i.max_reuse_count - 2 THEN 'near_limit'
            ELSE 'ok' END AS flag
FROM dialyzer_units du
JOIN items i    ON i.id = du.item_id
JOIN patients p ON p.id = du.patient_id
WHERE du.status = 'active';

CREATE OR REPLACE VIEW v_stock_on_hand AS
SELECT i.id AS item_id, i.sku, i.name, i.category,
       COALESCE(SUM(l.qty_on_hand), 0) AS qty_on_hand,
       i.reorder_level,
       COALESCE(SUM(CASE WHEN l.expiry_date < CURDATE() + INTERVAL 90 DAY
                         THEN l.qty_on_hand END), 0) AS expiring_90d,
       MIN(CASE WHEN l.qty_on_hand > 0 THEN l.expiry_date END) AS earliest_expiry,
       (COALESCE(SUM(l.qty_on_hand), 0) <= i.reorder_level) AS needs_reorder
FROM items i
LEFT JOIN stock_lots l ON l.item_id = i.id
WHERE i.is_active = 1
GROUP BY i.id, i.sku, i.name, i.category, i.reorder_level;

-- The inspection binder, generated rather than filed
CREATE OR REPLACE VIEW v_water_exceptions AS
SELECT 'daily_log' AS source, DATE(w.logged_at) AS on_date, ws.name AS system_name,
       'total_chlorine' AS parameter, w.total_chlorine_ppm AS value,
       0.1 AS limit_value, w.action_taken
FROM water_daily_logs w
JOIN water_systems ws ON ws.id = w.water_system_id
WHERE w.total_chlorine_ppm > 0.1
  AND w.logged_at > NOW() - INTERVAL 90 DAY
UNION ALL
SELECT 'lab_test', t.sampled_on, ws.name, t.test_type,
       t.result_num, t.limit_value, t.corrective_action
FROM water_quality_tests t
JOIN water_systems ws ON ws.id = t.water_system_id
WHERE t.passed = 0
  AND t.sampled_on > CURDATE() - INTERVAL 90 DAY;
