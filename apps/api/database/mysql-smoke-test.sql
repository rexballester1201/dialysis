-- =====================================================================
-- Clinical invariant tests.
--
-- NOT idempotent: this script loads its own fixtures, so it must run exactly
-- once against a freshly created + seeded database. Re-running it on a dirty
-- database fails on duplicate keys, which is a fixture collision and not a
-- schema defect.
--
--   mysql -u root -p < database/schema/mysql-schema.sql
--   mysql -u root -p < database/mysql-seed.sql
--   mysql -u root -p < database/mysql-smoke-test.sql
--
-- Mirror these as Pest feature tests (tests/Feature/ClinicalInvariantsTest.php),
-- where RefreshDatabase gives you a clean slate per test.
-- =====================================================================
USE dialysis;

INSERT INTO staff (public_id, employee_no, first_name, last_name, licence_no, email) VALUES
 ('01J0STAFF00000000000000MD0','MD-001','Ana','Reyes','PRC-12345','ana@example.test'),
 ('01J0STAFF00000000000000RN0','RN-014','Jose','Cruz','PRC-98765','jose@example.test');
SET @md = (SELECT id FROM staff WHERE employee_no='MD-001');
SET @rn = (SELECT id FROM staff WHERE employee_no='RN-014');
INSERT INTO role_staff (staff_id, role_code) VALUES (@md,'nephrologist'), (@rn,'nurse');

INSERT INTO patients (public_id, mrn, first_name, last_name, birth_date, sex,
                      first_dialysis_date, primary_nephrologist_id)
VALUES ('01J0PT00000000000000000100','MRN-0001','Maria','Santos','1968-04-12','female','2021-06-01',@md);
SET @pt = (SELECT id FROM patients WHERE mrn='MRN-0001');
INSERT INTO patient_identifiers (patient_id,id_type,id_value,is_primary)
VALUES (@pt,'philhealth','12-345678901-2',1);

-- ---- TEST 1: serology drives the cohort -----------------------------
INSERT INTO serology_results (patient_id,marker,result,specimen_date) VALUES
 (@pt,'hbsag','non_reactive','2025-01-10'),
 (@pt,'anti_hcv','non_reactive','2025-01-10'),
 (@pt,'hbsag','reactive','2026-01-15');            -- seroconverted
SELECT '== TEST 1: cohort should be hbv' AS test;
SELECT cohort, last_serology_date FROM v_patient_cohort WHERE patient_id=@pt;

INSERT INTO vascular_accesses (patient_id,access_type,side,site,created_on,first_used_on,status)
VALUES (@pt,'avf','left','radiocephalic','2021-05-01','2021-06-01','in_use');
SET @acc = LAST_INSERT_ID();
INSERT INTO dry_weights (patient_id,weight_kg,effective_from,set_by) VALUES
 (@pt,58.0,'2026-01-01',@md), (@pt,57.5,'2026-06-01',@md);

SET @dlz = (SELECT id FROM items WHERE sku='DLZ-F8');
INSERT INTO hd_prescriptions
 (patient_id,version,effective_from,effective_to,modality,sessions_per_week,duration_min,
  dialyzer_item_id,reuse_allowed,max_reuse_count,blood_flow_ml_min,vascular_access_id,
  dialysate_flow_ml_min,dialysate_na_mmol,dialysate_k_mmol,dialysate_ca_mmol,
  dialysate_hco3_mmol,dialysate_temp_c,anticoagulant,ac_loading_dose,ac_maintenance_hr,
  target_ktv,target_dry_weight_kg,prescribed_by)
VALUES (@pt,1,'2026-01-01',NULL,'hd',3,240,@dlz,1,6,300,@acc,500,138,2.0,1.50,32,36.5,
        'heparin',2000,1000,1.2,57.5,@md);
SET @rx1 = LAST_INSERT_ID();

DELIMITER $$
DROP PROCEDURE IF EXISTS run_invariant_tests$$
CREATE PROCEDURE run_invariant_tests()
BEGIN
  DECLARE ok INT DEFAULT 0;

  -- ---- TEST 2: overlapping prescription must be rejected ------------
  BEGIN
    DECLARE CONTINUE HANDLER FOR SQLSTATE '45000' SET ok = 1;
    SET ok = 0;
    INSERT INTO hd_prescriptions (patient_id,version,effective_from,duration_min)
    VALUES (@pt,2,'2026-06-01',210);
    IF ok = 1 THEN SELECT 'PASS: overlapping prescription rejected' AS result;
    ELSE SELECT 'FAIL: overlapping prescription was accepted' AS result; END IF;
  END;

  -- ---- TEST 12: high-alert drug without a witness -------------------
  BEGIN
    DECLARE CONTINUE HANDLER FOR SQLSTATE '45000' SET ok = 1;
    SET ok = 0;
    INSERT INTO medication_administrations
      (patient_id,medication_id,dose,dose_unit,route,administered_at,given_by)
    VALUES (@pt,(SELECT id FROM medication_refs WHERE generic_name='Heparin sodium'),
            2000,'IU','IV',NOW(3),@rn);
    IF ok = 1 THEN SELECT 'PASS: high-alert drug without witness rejected' AS result;
    ELSE SELECT 'FAIL: high-alert drug accepted with no witness' AS result; END IF;
  END;
END$$

DROP PROCEDURE IF EXISTS test_locked_session$$
CREATE PROCEDURE test_locked_session()
BEGIN
  DECLARE ok INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLSTATE '45000' SET ok = 1;
  UPDATE treatment_sessions SET ktv = 1.90 WHERE id = @sess;
  IF ok = 1 THEN SELECT 'PASS: locked session rejected the edit' AS result;
  ELSE SELECT 'FAIL: locked session was editable' AS result; END IF;
END$$

DROP PROCEDURE IF EXISTS test_duplicate_claim$$
CREATE PROCEDURE test_duplicate_claim()
BEGIN
  DECLARE ok INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLSTATE '23000' SET ok = 1;
  INSERT INTO claims (claim_no,patient_id,payer_id,service_from,service_to)
  VALUES ('CLM-2026-0901',@pt,(SELECT id FROM payers WHERE code='PHILHEALTH'),CURDATE(),CURDATE());
  INSERT INTO claim_sessions (session_id,claim_id,benefit_seq_no,amount)
  VALUES (@sess, LAST_INSERT_ID(), 48, 6350.00);
  IF ok = 1 THEN SELECT 'PASS: duplicate session claim rejected' AS result;
  ELSE SELECT 'FAIL: same session claimed twice' AS result; END IF;
END$$

DROP PROCEDURE IF EXISTS test_double_booking$$
CREATE PROCEDURE test_double_booking()
BEGIN
  DECLARE ok INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLSTATE '23000' SET ok = 1;
  INSERT INTO patients (public_id,mrn,first_name,last_name,birth_date,sex)
  VALUES ('01J0PT00000000000000000200','MRN-0002','Pedro','Dela Cruz','1975-02-02','male');
  INSERT INTO treatment_sessions (public_id,patient_id,session_date,shift_id,station_id,status)
  VALUES ('01J0SESS000000000000000020', LAST_INSERT_ID(), CURDATE(),
          (SELECT id FROM shifts WHERE code='AM'),
          (SELECT id FROM stations WHERE code='ISO-B1'), 'scheduled');
  IF ok = 1 THEN SELECT 'PASS: two patients in one chair/shift rejected' AS result;
  ELSE SELECT 'FAIL: chair double-booked' AS result; END IF;
END$$
DELIMITER ;

CALL run_invariant_tests();

-- Close version 1 correctly, open version 2
UPDATE hd_prescriptions SET effective_to='2026-06-01' WHERE id=@rx1;
INSERT INTO hd_prescriptions (patient_id,version,effective_from,duration_min,prescribed_by)
VALUES (@pt,2,'2026-06-01',240,@md);
SELECT '== current prescription resolves to version 2' AS test;
SELECT id, version, effective_from, effective_to FROM v_current_prescription WHERE patient_id=@pt;

-- ---- stock, dialyzer, machines --------------------------------------
INSERT INTO suppliers (name) VALUES ('Renal Supplies Inc');
SET @sup = LAST_INSERT_ID();
INSERT INTO stock_lots (item_id,lot_no,expiry_date,supplier_id,received_on,qty_received,qty_on_hand,unit_cost)
VALUES (@dlz,'LOT-A1','2027-12-31',@sup,'2026-07-01',100,100,1100),
       ((SELECT id FROM items WHERE sku='BLS-AV'),'LOT-B7','2028-06-30',@sup,'2026-07-01',300,300,250);
SET @lot_bls = (SELECT id FROM stock_lots WHERE lot_no='LOT-B7');
INSERT INTO dialyzer_units (item_id,lot_id,label_code,patient_id,first_used_on,use_count,initial_tcv_ml,current_tcv_ml)
VALUES (@dlz,(SELECT id FROM stock_lots WHERE lot_no='LOT-A1'),'DZ-0001',@pt,'2026-08-01',2,110.0,104.0);
SET @du = LAST_INSERT_ID();

INSERT INTO machines (asset_tag,manufacturer,model,serial_no,status,dedicated_cohort,data_export_mode) VALUES
 ('M-01','Fresenius','5008S','SN-0001','in_service','clean','network_hl7'),
 ('M-17','Nikkiso','DBB-27','SN-0017','in_service','hbv','manual');

-- ---- TEST 3: cohort violation is detectable -------------------------
INSERT INTO treatment_sessions
 (public_id,patient_id,session_date,shift_id,station_id,machine_id,prescription_id,
  status,pre_weight_kg,dry_weight_kg,primary_nurse_id)
VALUES ('01J0SESS000000000000000010',@pt,CURDATE(),
        (SELECT id FROM shifts WHERE code='AM'),
        (SELECT id FROM stations WHERE code='S-01'),      -- clean chair: WRONG
        (SELECT id FROM machines WHERE asset_tag='M-01'),
        (SELECT id FROM hd_prescriptions WHERE patient_id=@pt AND version=2),
        'scheduled',60.4,57.5,@rn);
SET @sess = LAST_INSERT_ID();
SELECT '== TEST 3: mis-assigned HBV patient is flagged' AS test;
SELECT session_id, mrn, cohort, station_code, asset_tag, dedicated_cohort FROM v_cohort_violation;

UPDATE treatment_sessions
   SET station_id=(SELECT id FROM stations WHERE code='ISO-B1'),
       machine_id=(SELECT id FROM machines WHERE asset_tag='M-17')
 WHERE id=@sess;
SELECT '== after correction, violations should be 0' AS test;
SELECT COUNT(*) AS violations FROM v_cohort_violation;

CALL test_double_booking();

-- ---- run the session -------------------------------------------------
UPDATE treatment_sessions SET
  status='in_progress', checked_in_at=NOW(3)-INTERVAL 260 MINUTE,
  started_at=NOW(3)-INTERVAL 255 MINUTE,
  dialyzer_unit_id=@du, dialyzer_use_no=3, bloodline_lot_id=@lot_bls,
  vascular_access_id=@acc, needle_gauge=16, cannulation_attempts=1,
  planned_duration_min=240, planned_uf_ml=2900,
  blood_flow_set_ml_min=300, dialysate_flow_ml_min=500,
  anticoagulant='heparin', ac_loading_dose=2000, ac_maintenance_hr=1000,
  pre_bp_sys=152, pre_bp_dia=88, pre_pulse=78, pre_temp_c=36.6
WHERE id=@sess;

INSERT INTO session_vitals (session_id,client_uuid,recorded_at,minutes_elapsed,bp_sys,bp_dia,
                            pulse,blood_flow_ml_min,venous_pressure_mmhg,uf_rate_ml_hr,
                            uf_volume_ml,rbv_pct,source,recorded_by)
SELECT @sess, CONCAT('01J0VITAL',LPAD(n,17,'0')),
       NOW(3)-INTERVAL (255 - n*30) MINUTE, n*30,
       150-n*6, 86-n*3, 78+n, 300, 120+n*4, 725, n*362, 100-n*1.8,'manual',@rn
FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
      UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8) g;

INSERT INTO session_events (session_id,occurred_at,event_code,severity,description,intervention,outcome,reported_by)
VALUES (@sess,NOW(3)-INTERVAL 80 MINUTE,'hypotension','moderate','BP 88/52, dizziness',
        'UF held, 200 mL NS bolus, Trendelenburg','BP recovered to 118/70',@rn),
       (@sess,NOW(3)-INTERVAL 55 MINUTE,'cramps','minor','Left calf cramp',
        'Reduced UF rate, hypertonic saline','Resolved',@rn);

INSERT INTO medication_administrations
 (session_id,patient_id,medication_id,dose,dose_unit,route,administered_at,timing,given_by)
VALUES (@sess,@pt,(SELECT id FROM medication_refs WHERE generic_name='Epoetin alfa'),
        4000,'IU','IV',NOW(3)-INTERVAL 30 MINUTE,'post',@rn);

UPDATE treatment_sessions SET
  status='completed', ended_at=NOW(3)-INTERVAL 15 MINUTE,
  termination_reason='completed_as_prescribed',
  post_weight_kg=57.6, net_uf_ml=2800, total_intake_ml=200,
  post_bp_sys=126, post_bp_dia=74, post_pulse=82, post_temp_c=36.4,
  pre_bun_mmol=22.4, post_bun_mmol=6.9, urr_pct=69.2, ktv=1.42,
  ktv_method='single_pool_daugirdas',
  ambulation='unassisted', discharge_condition='stable', discharged_at=NOW(3),
  physician_id=@md
WHERE id=@sess;

INSERT INTO stock_transactions (lot_id,item_id,move_type,qty,session_id,patient_id,performed_by)
VALUES (@lot_bls,(SELECT id FROM items WHERE sku='BLS-AV'),'issue_to_session',-1,@sess,@pt,@rn);
INSERT INTO dialyzer_reprocess_logs (dialyzer_unit_id,session_id,reprocessed_at,use_number,method,
                                     germicide,tcv_ml,tcv_pct_of_initial,pressure_test_passed,
                                     visual_ok,accepted,performed_by)
VALUES (@du,@sess,NOW(3),3,'automated','peracetic_acid',102.5,93.2,1,1,1,@rn);
UPDATE dialyzer_units SET use_count=3, current_tcv_ml=102.5 WHERE id=@du;

SELECT '== TEST 4: computed session columns' AS test;
SELECT pre_weight_kg, dry_weight_kg, idwg_kg, post_weight_kg, weight_loss_kg,
       actual_duration_min, ktv, urr_pct FROM treatment_sessions WHERE id=@sess;

SELECT '== TEST 5: stock decremented by trigger (expect 299)' AS test;
SELECT qty_on_hand FROM stock_lots WHERE id=@lot_bls;

-- ---- TEST 6: lock makes the record immutable ------------------------
UPDATE treatment_sessions SET nurse_signed_by=@rn, nurse_signed_at=NOW(3),
       physician_signed_by=@md, physician_signed_at=NOW(3), locked_at=NOW(3)
 WHERE id=@sess;
CALL test_locked_session();

SELECT '== TEST 6b: authorised amendment succeeds' AS test;
SET @allow_amendment = 1;
UPDATE treatment_sessions SET ktv = 1.45 WHERE id=@sess;
SET @allow_amendment = 0;
SELECT ktv FROM treatment_sessions WHERE id=@sess;

-- ---- TEST 7: benefit tracking ---------------------------------------
INSERT INTO patient_coverages (patient_id,payer_id,member_no,effective_from,effective_to,priority)
VALUES (@pt,(SELECT id FROM payers WHERE code='PHILHEALTH'),'12-345678901-2','2026-01-01','2027-01-01',1);
INSERT INTO benefit_periods (patient_id,program_id,period_start,period_end,sessions_allotted)
VALUES (@pt,(SELECT id FROM benefit_programs WHERE code='PH_HD_CURRENT'),'2026-01-01','2027-01-01',156);
SET @bper = LAST_INSERT_ID();
INSERT INTO claims (claim_no,patient_id,payer_id,program_id,benefit_period_id,
                    service_from,service_to,session_count,amount_claimed,status)
VALUES ('CLM-2026-0900',@pt,(SELECT id FROM payers WHERE code='PHILHEALTH'),
        (SELECT id FROM benefit_programs WHERE code='PH_HD_CURRENT'),@bper,
        CURDATE(),CURDATE(),1,6350.00,'ready');
INSERT INTO claim_sessions (session_id,claim_id,benefit_seq_no,amount)
VALUES (@sess, LAST_INSERT_ID(), 47, 6350.00);
SET @allow_amendment = 0;
UPDATE treatment_sessions SET benefit_claim_id=(SELECT id FROM claims WHERE claim_no='CLM-2026-0900')
 WHERE id=@sess;                                    -- admin column: allowed while locked

SELECT '== TEST 7: benefit utilisation' AS test;
SELECT mrn, program_code, sessions_allotted, sessions_claimed, sessions_remaining, amount_claimed
FROM v_benefit_utilisation;

CALL test_duplicate_claim();

SELECT '== TEST 9: daily board' AS test;
SELECT shift_code, station_code, machine, mrn, cohort, status, idwg_kg, net_uf_ml, primary_nurse
FROM v_daily_board WHERE session_date = CURDATE() AND status = 'completed';

SELECT '== TEST 10: monthly quality (sessions must be 1, not 2)' AS test;
SELECT month, sessions, completed, avg_ktv, avg_idwg_kg,
       sessions_with_hypotension, sessions_with_reportable_event
FROM v_monthly_quality WHERE patient_id=@pt;

SELECT '== TEST 11: dialyzer reuse status' AS test;
SELECT label_code, mrn, use_count, max_reuse_count, tcv_pct, flag FROM v_dialyzer_status;

DROP PROCEDURE IF EXISTS run_invariant_tests;
DROP PROCEDURE IF EXISTS test_locked_session;
DROP PROCEDURE IF EXISTS test_duplicate_claim;
DROP PROCEDURE IF EXISTS test_double_booking;
