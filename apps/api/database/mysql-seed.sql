-- Reference data. Values reflect a Philippine centre; swap payers and
-- benefit programs for MY/ID/SG. Idempotent.
USE dialysis;

INSERT IGNORE INTO facilities (id, name, country_code, timezone, currency, station_count)
VALUES (1, 'Your Dialysis Centre', 'PH', 'Asia/Manila', 'PHP', 20);

INSERT IGNORE INTO roles (code, name, description) VALUES
 ('admin','System Administrator','Full access, user management'),
 ('nephrologist','Nephrologist','Prescribes, signs off treatment records'),
 ('head_nurse','Head Nurse','Roster, scheduling, clinical oversight'),
 ('nurse','Dialysis Nurse','Runs sessions, charts the flow sheet'),
 ('technician','Renal Technician','Machines, water, reprocessing'),
 ('billing','Billing Officer','Invoices, claims, payer follow-up'),
 ('records','Records Officer','Registration, documents'),
 ('dietitian','Renal Dietitian','Nutrition assessment'),
 ('readonly','Read Only','Audit / management view');

-- weekday_mask bit 0 = Monday. 63 = Mon-Sat, 21 = Mon/Wed/Fri.
INSERT IGNORE INTO shifts (code, name, starts_at, ends_at, weekday_mask, sort_order) VALUES
 ('AM','Morning','06:00:00','10:30:00',63,1),
 ('MID','Midday','10:45:00','15:15:00',63,2),
 ('PM','Afternoon','15:30:00','20:00:00',63,3),
 ('NOC','Night','20:15:00','00:45:00',21,4);

INSERT IGNORE INTO diagnosis_refs (code, description, category) VALUES
 ('N18.6','End stage renal disease','primary_renal'),
 ('N18.5','Chronic kidney disease, stage 5','primary_renal'),
 ('E11.2','Type 2 diabetes mellitus with diabetic nephropathy','primary_renal'),
 ('I12.0','Hypertensive CKD with stage 5 CKD or ESRD','primary_renal'),
 ('N03.9','Chronic nephritic syndrome, unspecified','primary_renal'),
 ('Q61.3','Polycystic kidney, unspecified','primary_renal'),
 ('N11.9','Chronic tubulo-interstitial nephritis','primary_renal'),
 ('M32.14','Systemic lupus erythematosus, glomerular disease','primary_renal'),
 ('E11.9','Type 2 diabetes mellitus without complications','comorbidity'),
 ('I10','Essential hypertension','comorbidity'),
 ('I50.9','Heart failure, unspecified','comorbidity'),
 ('D63.1','Anaemia in chronic kidney disease','comorbidity'),
 ('B18.1','Chronic viral hepatitis B','comorbidity'),
 ('B18.2','Chronic viral hepatitis C','comorbidity');

INSERT IGNORE INTO event_refs (code, label, category, is_reportable) VALUES
 ('hypotension','Intradialytic hypotension','haemodynamic',0),
 ('hypertension','Intradialytic hypertension','haemodynamic',0),
 ('cramps','Muscle cramps','haemodynamic',0),
 ('nausea_vomiting','Nausea / vomiting','gi',0),
 ('headache','Headache','neuro',0),
 ('chest_pain','Chest pain','cardiac',1),
 ('arrhythmia','Arrhythmia','cardiac',1),
 ('dyspnoea','Dyspnoea','respiratory',1),
 ('chills_rigors','Chills / rigors (pyrogenic)','infection',1),
 ('fever','Fever during session','infection',1),
 ('allergic_reaction','Allergic / anaphylactoid reaction','allergy',1),
 ('haemolysis','Suspected haemolysis','circuit',1),
 ('air_embolism','Air detected / embolism','circuit',1),
 ('circuit_clotting','Circuit or dialyzer clotting','circuit',0),
 ('blood_leak','Blood leak alarm','circuit',1),
 ('needle_infiltration','Needle infiltration / haematoma','access',0),
 ('needle_dislodgement','Needle dislodgement','access',1),
 ('access_bleeding','Prolonged access bleeding','access',0),
 ('poor_access_flow','Poor access flow','access',0),
 ('cannulation_failure','Failed cannulation','access',0),
 ('machine_alarm','Machine alarm / fault','machine',0),
 ('power_failure','Power interruption','machine',1),
 ('water_alarm','Water system alarm','machine',1),
 ('seizure','Seizure','neuro',1),
 ('cardiac_arrest','Cardiac arrest','cardiac',1),
 ('fall','Patient fall','safety',1),
 ('other','Other','other',0);

INSERT IGNORE INTO lab_test_refs (code,name,unit,ref_low,ref_high,target_low,target_high,panel,sort_order) VALUES
 ('HGB','Haemoglobin','g/dL',12,16,10,11.5,'monthly',10),
 ('HCT','Haematocrit','%',36,48,30,36,'monthly',11),
 ('WBC','White cell count','10^9/L',4,11,NULL,NULL,'monthly',12),
 ('PLT','Platelet count','10^9/L',150,400,NULL,NULL,'monthly',13),
 ('K','Potassium (pre-HD)','mmol/L',3.5,5.1,3.5,5.5,'monthly',20),
 ('NA','Sodium','mmol/L',135,145,NULL,NULL,'monthly',21),
 ('CA','Calcium (corrected)','mmol/L',2.1,2.6,2.1,2.5,'monthly',22),
 ('PO4','Phosphate','mmol/L',0.8,1.5,1.1,1.8,'monthly',23),
 ('IPTH','Intact PTH','pg/mL',15,65,130,600,'quarterly',24),
 ('ALB','Albumin','g/L',35,50,40,NULL,'monthly',25),
 ('BUN_PRE','Urea nitrogen pre-HD','mmol/L',NULL,NULL,NULL,NULL,'monthly',30),
 ('BUN_POST','Urea nitrogen post-HD','mmol/L',NULL,NULL,NULL,NULL,'monthly',31),
 ('CREA','Creatinine','umol/L',NULL,NULL,NULL,NULL,'monthly',32),
 ('KTV','Kt/V (single pool)','',NULL,NULL,1.2,NULL,'monthly',40),
 ('URR','Urea reduction ratio','%',NULL,NULL,65,NULL,'monthly',41),
 ('FER','Ferritin','ng/mL',30,400,200,800,'quarterly',50),
 ('TSAT','Transferrin saturation','%',20,50,20,50,'quarterly',51),
 ('HBA1C','HbA1c','%',4,6,NULL,7.5,'quarterly',52);

-- Loaded only into an empty table. The unique key on medication_refs includes
-- brand_name, strength and form, which are nullable, and a UNIQUE key ignores
-- any row with a NULL in it (CLAUDE.md, MySQL rule 9). Every row here has no
-- brand, so INSERT IGNORE added the whole list again on a second import --
-- heparin and all, each copy separately flagged. "No rows yet" needs no
-- comparison, so it also cannot trip over a connection's collation.
INSERT INTO medication_refs (generic_name,brand_name,form,strength,unit,is_pnf,is_high_alert)
SELECT * FROM (
 SELECT 'Epoetin alfa' AS generic_name, NULL AS brand_name, 'prefilled_syringe' AS form, '4000 IU' AS strength, 'IU' AS unit, 1 AS is_pnf, 0 AS is_high_alert
 UNION ALL SELECT 'Darbepoetin alfa',NULL,'prefilled_syringe','40 mcg','mcg',0,0
 UNION ALL SELECT 'Iron sucrose',NULL,'ampoule','100 mg/5 mL','mg',1,0
 UNION ALL SELECT 'Heparin sodium',NULL,'vial','5000 IU/mL','IU',1,1
 UNION ALL SELECT 'Enoxaparin',NULL,'prefilled_syringe','40 mg','mg',1,1
 UNION ALL SELECT 'Calcium gluconate',NULL,'ampoule','10%','mL',1,1
 UNION ALL SELECT 'Sodium chloride 0.9%',NULL,'bag','500 mL','mL',1,0
 UNION ALL SELECT 'Dextrose 50%',NULL,'ampoule','50 mL','mL',1,1
 UNION ALL SELECT 'Calcitriol',NULL,'ampoule','1 mcg','mcg',1,0
 UNION ALL SELECT 'Lidocaine 2%',NULL,'vial','2%','mL',1,0
 UNION ALL SELECT 'Alteplase',NULL,'vial','2 mg','mg',0,1
 UNION ALL SELECT 'Vancomycin',NULL,'vial','500 mg','mg',1,1
 UNION ALL SELECT 'Diphenhydramine',NULL,'ampoule','50 mg','mg',1,0
 UNION ALL SELECT 'Hydrocortisone',NULL,'vial','100 mg','mg',1,0
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM medication_refs);

INSERT IGNORE INTO payers (code,name,kind,country_code,claim_format) VALUES
 ('PHILHEALTH','Philippine Health Insurance Corporation','national_insurance','PH','eclaims_xml'),
 ('PCSO','Philippine Charity Sweepstakes Office','government_subsidy','PH','portal_manual'),
 ('DSWD','DSWD Medical Assistance','government_subsidy','PH','portal_manual'),
 ('SELF','Self-pay / Out of pocket','self_pay',NULL,NULL),
 ('HMO','HMO (generic)','hmo',NULL,'portal_manual');

-- Effective-dated because the rate keeps moving. VERIFY against the latest
-- circular before go-live.
INSERT IGNORE INTO benefit_programs
 (payer_id,code,name,modality,sessions_per_period,period_kind,case_rate,
  facility_fee,professional_fee,currency,no_balance_billing,effective_from,effective_to,circular_ref,notes)
SELECT id,'PH_HD_156_2023','PhilHealth HD Package (2023 rate)','hd',156,'calendar_year',
       2600.00,2250.00,350.00,'PHP',1,'2023-03-01','2024-07-01','PhilHealth Circular 2023-0009',
       'Superseded. Kept so historical claims re-price correctly.'
FROM payers WHERE code='PHILHEALTH';

INSERT IGNORE INTO benefit_programs
 (payer_id,code,name,modality,sessions_per_period,period_kind,case_rate,
  currency,no_balance_billing,effective_from,circular_ref,notes)
SELECT id,'PH_HD_CURRENT','PhilHealth Haemodialysis Package (current)','hd',156,'calendar_year',
       6350.00,'PHP',1,'2024-10-09','PhilHealth Circular 2024-0023',
       'P6,350 x 156 = P990,600/yr. Confirm the facility/professional split.'
FROM payers WHERE code='PHILHEALTH';

INSERT IGNORE INTO service_items (code,name,category,unit_price) VALUES
 ('HD-SESSION','Haemodialysis session (4 hours)','dialysis',6350.00),
 ('HDF-SESSION','Haemodiafiltration session','dialysis',7500.00),
 ('PROF-FEE','Nephrologist professional fee','professional',350.00),
 ('DIALYZER-NEW','New dialyzer','supply',900.00),
 ('BLOODLINE','Blood tubing set','supply',250.00),
 ('NEEDLE-16G','Fistula needle 16G (pair)','supply',120.00),
 ('EPO-4000','Erythropoietin 4000 IU','drug',900.00),
 ('IRON-100','Iron sucrose 100 mg','drug',450.00),
 ('LAB-MONTHLY','Monthly laboratory panel','lab',1500.00);

-- 16 general chairs + 2 HBV + 2 HCV isolation
INSERT IGNORE INTO stations (code, kind, room)
SELECT CONCAT('S-', LPAD(n,2,'0')), 'standard', 'Main Floor'
FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
      UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11
      UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16) g;
INSERT IGNORE INTO stations (code, kind, room) VALUES
 ('ISO-B1','isolation','Isolation Room B'),
 ('ISO-B2','isolation','Isolation Room B'),
 ('ISO-C1','isolation','Isolation Room C'),
 ('ISO-C2','isolation','Isolation Room C');

INSERT IGNORE INTO station_cohorts (station_id, cohort)
SELECT id,'clean' FROM stations WHERE code LIKE 'S-%';
INSERT IGNORE INTO station_cohorts (station_id, cohort)
SELECT id,'hbv' FROM stations WHERE code LIKE 'ISO-B%';
INSERT IGNORE INTO station_cohorts (station_id, cohort)
SELECT id,'hcv' FROM stations WHERE code LIKE 'ISO-C%';

-- A placeholder for the unit's own RO system -- rename it. Only when there is
-- none: water_systems has no unique key, so INSERT IGNORE added another
-- "RO Unit A" on every re-import and the Water screen offered both. Matching
-- on the name would still re-add it once the unit had renamed theirs.
INSERT INTO water_systems (name, loop_type, is_active)
SELECT 'RO Unit A', 'indirect_loop', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM water_systems);

INSERT IGNORE INTO items (sku,name,category,unit_of_measure,membrane,surface_area_m2,flux,
                          is_reusable,max_reuse_count,reorder_level,default_cost) VALUES
 ('DLZ-F6','Dialyzer F6HPS 1.3 m2','dialyzer','piece','polysulfone',1.3,'low',1,6,100,900),
 ('DLZ-F8','Dialyzer F8HPS 1.8 m2','dialyzer','piece','polysulfone',1.8,'high',1,6,80,1100),
 ('BLS-AV','Bloodline set A/V','bloodline','set',NULL,NULL,NULL,0,NULL,150,250),
 ('NDL-16','Fistula needle 16G','fistula_needle','pair',NULL,NULL,NULL,0,NULL,300,120),
 ('CONC-A','Acid concentrate 10 L','concentrate_acid','container',NULL,NULL,NULL,0,NULL,40,350),
 ('CONC-B','Bicarbonate cartridge','concentrate_bicarb','cartridge',NULL,NULL,NULL,0,NULL,60,280),
 ('NS-500','Normal saline 500 mL','saline','bag',NULL,NULL,NULL,0,NULL,200,60),
 ('PAA-20','Peracetic acid germicide','disinfectant','litre',NULL,NULL,NULL,0,NULL,20,800);
