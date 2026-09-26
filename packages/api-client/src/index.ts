/**
 * Typed client for the dialysis API.
 *
 * The Zod schemas mirror the PHP API Resources one field at a time. They are the
 * contract: if the server drops or renames a field, parsing fails loudly here
 * rather than surfacing as `undefined` three components deep in a flow sheet.
 *
 * Phase 0 covers auth, health and reading one patient. Phase 1 adds the registry
 * and scheduling surface.
 */
import { z } from 'zod'

/* -------------------------------------------------------------------------- */
/* Schemas -- mirror the API Resources under app/Domain                        */
/* -------------------------------------------------------------------------- */

/** Mirrors TokenResource. */
export const tokenSchema = z.object({
  token: z.string(),
  abilities: z.array(z.string()),
  expires_at: z.string().nullable(),
  device_id: z.string().nullable(),
  staff: z.object({
    public_id: z.string(),
    full_name: z.string(),
  }),
})

export const cohortSchema = z.enum(['clean', 'hbv', 'hcv'])

/** Mirrors PatientResource. Note there is no `id`: the server never sends one. */
export const patientSchema = z.object({
  public_id: z.string(),
  mrn: z.string(),
  first_name: z.string(),
  middle_name: z.string().nullable(),
  last_name: z.string(),
  suffix: z.string().nullable(),
  full_name: z.string(),
  birth_date: z.string(),
  sex: z.string(),
  blood_type: z.string().nullable(),
  status: z.string(),
  cohort: cohortSchema,
  first_dialysis_date: z.string().nullable(),
})

const healthCheckSchema = z.object({
  status: z.enum(['ok', 'fail', 'not_configured']),
}).catchall(z.unknown())

/** Mirrors HealthController. */
export const healthSchema = z.object({
  status: z.enum(['ok', 'fail']),
  checked_at: z.string(),
  checks: z.object({
    database: healthCheckSchema,
    redis: healthCheckSchema,
    migrations: healthCheckSchema,
    schema: healthCheckSchema,
    backup: healthCheckSchema,
  }),
})

/** Laravel's paginator envelope around a resource collection. */
export const patientPageSchema = z.object({
  data: z.array(patientSchema),
  links: z.object({}).catchall(z.unknown()),
  meta: z.object({
    current_page: z.number(),
    last_page: z.number(),
    per_page: z.number(),
    total: z.number(),
  }).catchall(z.unknown()),
})

/** Mirrors SerologyResultResource. */
export const serologyResultSchema = z.object({
  marker: z.enum(['hbsag', 'anti_hbs', 'anti_hbc', 'anti_hcv', 'hcv_rna', 'hiv', 'vdrl', 'hbv_dna']),
  result: z.enum(['reactive', 'non_reactive', 'indeterminate', 'pending']),
  titre: z.union([z.string(), z.number()]).nullable(),
  specimen_date: z.string(),
  resulted_on: z.string().nullable(),
  lab_name: z.string().nullable(),
  recorded_at: z.string(),
})

export const serologyListSchema = z.array(serologyResultSchema)

/** A booking the patient's new cohort no longer permits. */
export const affectedSessionSchema = z.object({
  session_id: z.number(),
  session_date: z.string(),
  cohort: cohortSchema,
  station_code: z.string().nullable(),
  asset_tag: z.string().nullable(),
  dedicated_cohort: z.string().nullable(),
})

export const serologyOutcomeSchema = z.object({
  result: serologyResultSchema,
  cohort_before: cohortSchema,
  cohort_after: cohortSchema,
  cohort_changed: z.boolean(),
  affected_sessions: z.array(affectedSessionSchema),
})

/** One chair on the day's board, from v_daily_board. */
export const boardEntrySchema = z.object({
  session_id: z.number(),
  public_id: z.string(),
  session_date: z.string(),
  shift_code: z.string().nullable(),
  station_code: z.string().nullable(),
  machine: z.string().nullable(),
  mrn: z.string(),
  full_name: z.string(),
  cohort: cohortSchema.nullable(),
  status: z.string(),
  primary_nurse: z.string().nullable(),
}).catchall(z.unknown())

export const boardSchema = z.object({
  date: z.string(),
  sessions: z.array(boardEntrySchema),
})

export const boardGenerationSchema = z.object({
  date: z.string(),
  created: z.number(),
  skipped: z.number(),
  /** Chair clashes, in words. Never resolved automatically -- a human picks. */
  clashes: z.array(z.string()),
})

/** Mirrors TreatmentSessionResource. */
export const treatmentSessionSchema = z.object({
  public_id: z.string(),
  session_date: z.string(),
  status: z.enum([
    'scheduled', 'checked_in', 'in_progress', 'completed',
    'aborted', 'missed', 'cancelled', 'refused',
  ]),
  modality: z.string(),

  // The fact a client acts on: once locked, the flow sheet is closed and a
  // correction has to go through the amendment path.
  is_locked: z.boolean(),
  locked_at: z.string().nullable(),
  nurse_signed_at: z.string().nullable(),
  physician_signed_at: z.string().nullable(),

  checked_in_at: z.string().nullable(),
  started_at: z.string().nullable(),
  ended_at: z.string().nullable(),
  actual_duration_min: z.number().nullable(),

  // Decimals arrive as strings: money and weights are DECIMAL server-side and
  // must not be round-tripped through a float.
  pre_weight_kg: z.string().nullable(),
  dry_weight_kg: z.string().nullable(),
  post_weight_kg: z.string().nullable(),
  idwg_kg: z.string().nullable(),
  weight_loss_kg: z.string().nullable(),

  planned_duration_min: z.number().nullable(),
  planned_uf_ml: z.number().nullable(),
  net_uf_ml: z.number().nullable(),

  ktv: z.string().nullable(),
  urr_pct: z.union([z.string(), z.number()]).nullable(),
  termination_reason: z.string().nullable(),

  patient: z.object({
    public_id: z.string().nullable(),
    mrn: z.string().nullable(),
    full_name: z.string().nullable(),
  }),
})

export const sessionPageSchema = z.object({
  data: z.array(treatmentSessionSchema),
  links: z.object({}).catchall(z.unknown()),
  meta: z.object({}).catchall(z.unknown()),
})

/** Mirrors SessionVitalResource. map_mmhg is computed by MySQL. */
export const sessionVitalSchema = z.object({
  recorded_at: z.string(),
  minutes_elapsed: z.number().nullable(),
  bp_sys: z.number().nullable(),
  bp_dia: z.number().nullable(),
  map_mmhg: z.string().nullable(),
  pulse: z.number().nullable(),
  temp_c: z.string().nullable(),
  spo2_pct: z.number().nullable(),
  comment: z.string().nullable(),
  source: z.string(),
}).catchall(z.unknown())

export const sessionEventSchema = z.object({
  occurred_at: z.string(),
  event_code: z.string(),
  severity: z.enum(['minor', 'moderate', 'severe', 'life_threatening']),
  description: z.string().nullable(),
  intervention: z.string().nullable(),
  outcome: z.string().nullable(),
})

/**
 * Whether the unit may dialyse today.
 *
 * `cleared: false` with no reading at all is the normal morning state, not an
 * error: the check simply has not been done yet. Silence is never a pass.
 */
export const waterClearanceSchema = z.object({
  cleared: z.boolean(),
  reason: z.string().nullable(),
  checked_at: z.string().nullable(),
  total_chlorine_ppm: z.string().nullable(),
})

/** What the start gate would decide now -- not always what the latest reading suggests. */
export const waterGateSchema = z.object({
  treatment_started: z.boolean(),
  start_allowed: z.boolean(),
})

/** A reading as MySQL's DECIMAL gives it back: a string, or nothing measured. */
const reading = z.string().nullable()

export const waterLogSchema = z.object({
  /** ISO-8601 with its offset. Render it in the unit's timezone, not the device's. */
  logged_at: z.string(),
  /** When it was typed in -- visible, so an entry made later than the check is too. */
  recorded_at: z.string().nullable(),
  system_name: z.string(),
  shift_code: z.string().nullable(),
  total_chlorine_ppm: reading,
  free_chlorine_ppm: reading,
  ph: reading,
  hardness_ppm: reading,
  feed_pressure_psi: reading,
  product_pressure_psi: reading,
  reject_pressure_psi: reading,
  feed_conductivity_us: reading,
  product_conductivity_us: reading,
  rejection_pct: reading,
  temperature_c: reading,
  softener_salt_ok: z.boolean().nullable(),
  carbon_tank_ok: z.boolean().nullable(),
  is_out_of_range: z.boolean(),
  action_taken: z.string().nullable(),
  logged_by: z.string().nullable(),
})

/**
 * One of the unit's days of water checks. `date` and `today` are the unit's
 * calendar days (facilities.timezone), which is what invariant 9 counts by --
 * not the UTC date and not the device's.
 */
export const waterDaySchema = z.object({
  date: z.string(),
  today: z.string(),
  timezone: z.string(),
  action_limit_ppm: z.number(),
  clearance: waterClearanceSchema,
  gate: waterGateSchema,
  logs: z.array(waterLogSchema),
  systems: z.array(z.object({ id: z.number(), name: z.string() })),
  shifts: z.array(z.object({ code: z.string(), name: z.string() })),
  /** Whether the person asking may record a check: technician, head nurse or admin. */
  can_record: z.boolean(),
})

export const waterLogResultSchema = z.object({
  logged_at: z.string(),
  /** The unit's day this check counts for. */
  date: z.string(),
  total_chlorine_ppm: z.union([z.string(), z.number()]).nullable(),
  is_out_of_range: z.boolean(),
  action_limit_ppm: z.number(),
  clearance: waterClearanceSchema,
  gate: waterGateSchema,
})

/**
 * Mirrors StoreWaterLogRequest. Readings go as strings so a decimal typed at
 * the desk reaches the server unrounded. `action_taken` is required when total
 * chlorine is above the action limit; the server enforces it. Leave `logged_at`
 * out to mean "now, by the server's clock".
 */
export interface WaterCheckInput {
  water_system_id: number
  total_chlorine_ppm: string
  shift_code?: string
  free_chlorine_ppm?: string
  ph?: string
  hardness_ppm?: string
  feed_pressure_psi?: string
  product_pressure_psi?: string
  reject_pressure_psi?: string
  feed_conductivity_us?: string
  product_conductivity_us?: string
  rejection_pct?: string
  temperature_c?: string
  softener_salt_ok?: boolean
  carbon_tank_ok?: boolean
  action_taken?: string
  logged_at?: string
}

/** A physical dialyzer. `refusal_reason` is null when it may be issued. */
export const dialyzerUnitSchema = z.object({
  label_code: z.string(),
  status: z.enum(['active', 'discarded', 'quarantined', 'failed_test']),
  use_count: z.number(),
  max_reuse_count: z.number().nullable(),
  tcv_pct: z.union([z.string(), z.number()]).nullable(),
  first_used_on: z.string().nullable(),
  discard_reason: z.string().nullable(),
  refusal_reason: z.string().nullable(),
})

export const dialyzerListSchema = z.object({
  minimum_tcv_pct: z.number(),
  units: z.array(dialyzerUnitSchema),
})

export const reprocessResultSchema = z.object({
  label_code: z.string(),
  use_count: z.number(),
  tcv_pct: z.union([z.string(), z.number()]).nullable(),
  status: z.string(),
  discard_reason: z.string().nullable(),
  issuable_again: z.boolean(),
})

/**
 * The payer package in force. Every field here is read from an effective-dated
 * row: the case rate and the session cap have both moved more than once, so a
 * client that hardcodes either will be quoting last year's money.
 */
export const benefitProgramSchema = z.object({
  code: z.string(),
  name: z.string(),
  case_rate: z.union([z.string(), z.number()]).nullable(),
  currency: z.string(),
  sessions_per_period: z.number(),
  period_kind: z.string(),
  no_balance_billing: z.boolean(),
  /** The circular the rate came from -- what you check when a payer disputes. */
  circular_ref: z.string().nullable(),
})

export const benefitUtilisationSchema = z.object({
  sessions_allotted: z.number(),
  sessions_claimed: z.number(),
  sessions_remaining: z.number(),
}).catchall(z.unknown())

/**
 * Money is a DECIMAL(12,2) string from the server and stays one. A number here
 * would invite arithmetic in floating point, and the server already did the sum
 * in bcmath -- if it ever arrives as a number, parsing fails rather than quietly
 * rounding someone's bill.
 */
const money = z.string()

/** Who a claim or invoice is for, by public_id -- the row id never leaves the server. */
const billedPatientSchema = z.object({
  public_id: z.string(),
  mrn: z.string(),
  full_name: z.string().nullable(),
})

const claimableSessionSchema = z.object({
  public_id: z.string(),
  session_date: z.string(),
  modality: z.string(),
})

/**
 * What could be claimed, grouped the way a claim must be built: one program,
 * one benefit period. The server refuses a claim that crosses either, so a
 * screen offers a claim per group rather than one over everything.
 */
export const claimableSchema = z.object({
  groups: z.array(z.object({
    program: benefitProgramSchema,
    period_start: z.string(),
    period_end: z.string(),
    utilisation: benefitUtilisationSchema,
    sessions: z.array(claimableSessionSchema),
  })),
  /** Sessions no program covers on their date, with the reason. Shown, never dropped. */
  unclaimable: z.array(claimableSessionSchema.extend({ reason: z.string() })),
})

/** One row of the claims list. */
export const claimSummarySchema = z.object({
  claim_no: z.string(),
  status: z.string(),
  patient: billedPatientSchema,
  program_code: z.string().nullable(),
  service_from: z.string(),
  service_to: z.string(),
  session_count: z.number(),
  amount_claimed: money,
  amount_approved: money.nullable(),
  amount_paid: money.nullable(),
})

export const claimPageSchema = z.object({
  data: z.array(claimSummarySchema),
  links: z.object({}).catchall(z.unknown()),
  meta: z.object({
    current_page: z.number(),
    last_page: z.number(),
    per_page: z.number(),
    total: z.number(),
  }).catchall(z.unknown()),
})

/**
 * A claim, and what may happen to it next.
 *
 * `next_statuses` and `accepts_remittance` come from the ledger's own
 * transition table, so a screen asks the rule rather than keeping a copy of it
 * that drifts. approved / partially_paid / paid never appear in next_statuses:
 * those are set by the remittance, from the money.
 */
export const claimDetailSchema = z.object({
  claim_no: z.string(),
  status: z.string(),
  patient: billedPatientSchema.nullable(),
  program: benefitProgramSchema.nullable(),
  utilisation: benefitUtilisationSchema.extend({
    period_start: z.string(),
    period_end: z.string(),
  }).nullable(),
  service_from: z.string(),
  service_to: z.string(),
  session_count: z.number(),
  amount_claimed: money,
  amount_approved: money.nullable(),
  amount_paid: money.nullable(),
  /** approved - paid, computed by the server in bcmath. Null until something is approved. */
  amount_outstanding: money.nullable(),
  denial_code: z.string().nullable(),
  denial_reason: z.string().nullable(),
  external_ref: z.string().nullable(),
  submitted_at: z.string().nullable(),
  acknowledged_at: z.string().nullable(),
  paid_at: z.string().nullable(),
  next_statuses: z.array(z.string()),
  accepts_remittance: z.boolean(),
  sessions: z.array(z.object({
    public_id: z.string(),
    session_date: z.string(),
    modality: z.string(),
    benefit_seq_no: z.number().nullable(),
    amount: money.nullable(),
  })),
  history: z.array(z.object({
    status: z.string(),
    /** ISO-8601 with its offset -- never a naive MySQL DATETIME. */
    changed_at: z.string(),
    remarks: z.string().nullable(),
    changed_by: z.string().nullable(),
  })),
})

export const claimsOutstandingSchema = z.object({
  claimed: money,
  approved: money,
  paid: money,
  outstanding: money,
})

/** What the payer decided, as keyed from its remittance advice. Totals to date, not this payment alone. */
export interface RemittanceInput {
  amount_approved: string
  amount_paid: string
  denial_code?: string
  denial_reason?: string
  external_ref?: string
}

export const invoiceableSchema = z.object({
  sessions: z.array(z.object({
    public_id: z.string(),
    session_date: z.string(),
    modality: z.string(),
    status: z.string(),
    claim_no: z.string().nullable(),
    claim_status: z.string().nullable(),
    payer_amount: money.nullable(),
    /** Null when the price list has no entry for the modality; drafting it is then refused. */
    list_price: money.nullable(),
    service_item: z.string().nullable(),
  })),
})

/** One row of the invoices list. */
export const invoiceSummarySchema = z.object({
  invoice_no: z.string(),
  issued_on: z.string(),
  status: z.string(),
  currency: z.string(),
  patient: billedPatientSchema,
  subtotal: money,
  payer_covered: money,
  patient_due: money,
  amount_paid: money,
  balance: money,
})

export const invoicePageSchema = z.object({
  data: z.array(invoiceSummarySchema),
  links: z.object({}).catchall(z.unknown()),
  meta: z.object({
    current_page: z.number(),
    last_page: z.number(),
    per_page: z.number(),
    total: z.number(),
  }).catchall(z.unknown()),
})

export const invoiceDetailSchema = z.object({
  invoice_no: z.string(),
  issued_on: z.string(),
  due_on: z.string().nullable(),
  status: z.string(),
  currency: z.string(),
  patient: billedPatientSchema.nullable(),
  subtotal: money,
  discount: money,
  tax: money,
  payer_covered: money,
  patient_due: money,
  amount_paid: money,
  /** Generated by MySQL: patient_due - amount_paid. */
  balance: money,
  notes: z.string().nullable(),
  can_issue: z.boolean(),
  can_void: z.boolean(),
  takes_payments: z.boolean(),
  lines: z.array(z.object({
    description: z.string(),
    qty: money,
    unit_price: money,
    discount: money,
    line_total: money,
    session_public_id: z.string().nullable(),
    session_date: z.string().nullable(),
    claim_no: z.string().nullable(),
    claim_status: z.string().nullable(),
  })),
  payments: z.array(z.object({
    paid_on: z.string(),
    amount: money,
    method: z.string(),
    reference_no: z.string().nullable(),
    notes: z.string().nullable(),
    received_by: z.string().nullable(),
  })),
})

/** Mirrors RecordPaymentRequest. A negative amount is a refund. */
export interface PaymentInput {
  amount: string
  method: 'cash' | 'card' | 'bank_transfer' | 'ewallet' | 'cheque' | 'payer_remittance' | 'adjustment'
  paid_on?: string
  reference_no?: string
  notes?: string
}

/**
 * The detective controls, as the console shows them.
 *
 * Rows are permissive on purpose: these views exist to surface anything that
 * slipped past a service boundary, and a schema that rejected an unfamiliar
 * column would hide exactly the row somebody needs to see.
 */
export const cohortViolationSchema = z.object({
  session_id: z.number().optional(),
  session_date: z.string().nullish(),
  mrn: z.string().nullish(),
  full_name: z.string().nullish(),
  cohort: z.string().nullish(),
  station_code: z.string().nullish(),
  asset_tag: z.string().nullish(),
  dedicated_cohort: z.string().nullish(),
}).catchall(z.unknown())

export const cohortViolationReportSchema = z.object({
  from: z.string(),
  violations: z.array(cohortViolationSchema),
}).catchall(z.unknown())

export const waterExceptionSchema = z.object({
  source: z.string().nullish(),
  on_date: z.string().nullish(),
  system_name: z.string().nullish(),
  parameter: z.string().nullish(),
  value: z.union([z.string(), z.number()]).nullish(),
  limit_value: z.union([z.string(), z.number()]).nullish(),
  action_taken: z.string().nullish(),
}).catchall(z.unknown())

export const waterExceptionReportSchema = z.object({
  exceptions: z.array(waterExceptionSchema),
}).catchall(z.unknown())

export const dialyzerExceptionSchema = z.object({
  label_code: z.string().nullish(),
  mrn: z.string().nullish(),
  full_name: z.string().nullish(),
  dialyzer: z.string().nullish(),
  tcv_pct: z.union([z.string(), z.number()]).nullish(),
  use_count: z.number().nullish(),
  max_reuse_count: z.number().nullish(),
  status: z.string().nullish(),
  flag: z.string().nullish(),
}).catchall(z.unknown())

export const dialyzerExceptionReportSchema = z.object({
  dialyzers: z.array(dialyzerExceptionSchema),
}).catchall(z.unknown())

export const qualityReportSchema = z.object({
  month: z.string(),
  source: z.string(),
  summarised_at: z.string().nullable(),
  targets: z.object({ ktv: z.number(), urr_pct: z.number() }).catchall(z.unknown()),
  patients: z.array(z.object({}).catchall(z.unknown())),
}).catchall(z.unknown())

export const utilisationReportSchema = z.object({
  from: z.string(),
  to: z.string(),
  stations: z.array(z.object({}).catchall(z.unknown())),
}).catchall(z.unknown())

/**
 * Dry weight is effective-dated, never a mutable column, so the API answers with
 * the current value *and* the history behind it. Both matter: the number drives
 * today's UF goal, and the history is why last month's IDWG figures still read
 * the way they do.
 */
export const dryWeightSchema = z.object({
  current_kg: z.union([z.string(), z.number()]).nullable(),
  history: z.array(z.object({
    weight_kg: z.union([z.string(), z.number()]),
    effective_from: z.string(),
    reason: z.string().nullable(),
  }).catchall(z.unknown())),
}).catchall(z.unknown())

export const prescriptionVersionSchema = z.object({
  version: z.number(),
  modality: z.string(),
  duration_min: z.number().nullable(),
  sessions_per_week: z.number().nullable(),
  blood_flow_ml_min: z.number().nullable(),
  anticoagulant: z.string().nullable(),
  effective_from: z.string(),
  effective_to: z.string().nullable(),
  change_reason: z.string().nullable(),
}).catchall(z.unknown())

export const prescriptionsSchema = z.object({
  current: z.object({}).catchall(z.unknown()).nullable(),
  versions: z.array(prescriptionVersionSchema),
}).catchall(z.unknown())

export const standingScheduleSchema = z.object({
  current: z.object({}).catchall(z.unknown()).nullable(),
  history: z.array(z.object({
    weekday_mask: z.number(),
    effective_from: z.string(),
    effective_to: z.string().nullable(),
    shift_code: z.string().nullable(),
    station_code: z.string().nullable(),
    notes: z.string().nullable(),
  }).catchall(z.unknown())),
}).catchall(z.unknown())

export const dryWeightSetSchema = z.object({
  current_kg: z.union([z.string(), z.number()]).nullable(),
}).catchall(z.unknown())

/* ---- unit settings ------------------------------------------------------ */

export const settingsStationSchema = z.object({
  id: z.number(),
  code: z.string(),
  kind: z.string().nullish(),
  room: z.string().nullish(),
  is_active: z.boolean(),
  /**
   * An EMPTY array means unrestricted, not "no cohorts allowed".
   * CohortGuard::stationAccepts() lets any patient into a chair with no
   * declared cohorts, so the screen must say so -- the intuitive reading is
   * the opposite one.
   */
  cohorts: z.array(z.string()),
}).catchall(z.unknown())

export const settingsMedicationSchema = z.object({
  id: z.number(),
  generic_name: z.string(),
  brand_name: z.string().nullish(),
  strength: z.string().nullish(),
  unit: z.string().nullish(),
  is_high_alert: z.boolean(),
}).catchall(z.unknown())

export const settingsStaffSchema = z.object({
  public_id: z.string(),
  full_name: z.string(),
  employee_no: z.string().nullish(),
  email: z.string().nullish(),
  is_active: z.boolean(),
  roles: z.array(z.string()),
}).catchall(z.unknown())

export const settingsProgramSchema = z.object({
  id: z.number(),
  code: z.string(),
  name: z.string(),
  modality: z.string(),
  case_rate: z.union([z.string(), z.number()]),
  sessions_per_period: z.number().nullish(),
  period_kind: z.string().nullish(),
  no_balance_billing: z.union([z.boolean(), z.number()]).nullish(),
  effective_from: z.string(),
  effective_to: z.string().nullish(),
  circular_ref: z.string().nullish(),
  currency: z.string().nullish(),
  payer_name: z.string().nullish(),
}).catchall(z.unknown())

export const settingsSchema = z.object({
  facility: z.object({}).catchall(z.unknown()).nullable(),
  stations: z.array(settingsStationSchema),
  benefit_programs: z.array(settingsProgramSchema),
  high_alert: z.array(settingsMedicationSchema),
  staff: z.array(settingsStaffSchema),
  roles: z.array(z.object({
    code: z.string(),
    name: z.string(),
    description: z.string().nullish(),
  }).catchall(z.unknown())),
}).catchall(z.unknown())

/* ---- labs ---------------------------------------------------------------- */

/**
 * Two ranges, and they answer different questions.
 *
 *   ref_low/ref_high        the laboratory's interval for a general population
 *   target_low/target_high  where a dialysis patient should sit
 *
 * Haemoglobin 11 g/dL is low against the reference (12-16) and exactly on target
 * for a dialysed patient (10-11.5). A screen that shows only the first turns the
 * whole panel red and teaches staff to ignore it, so both travel together and
 * are never merged.
 */
export const labTestSchema = z.object({
  code: z.string(),
  name: z.string(),
  unit: z.string().nullish(),
  ref_low: z.union([z.string(), z.number()]).nullish(),
  ref_high: z.union([z.string(), z.number()]).nullish(),
  target_low: z.union([z.string(), z.number()]).nullish(),
  target_high: z.union([z.string(), z.number()]).nullish(),
  panel: z.string().nullish(),
  sort_order: z.number().nullish(),
}).catchall(z.unknown())

export const labResultSchema = z.object({
  id: z.number(),
  test_code: z.string(),
  name: z.string().nullish(),
  panel: z.string().nullish(),
  value_num: z.union([z.string(), z.number()]).nullish(),
  value_text: z.string().nullish(),
  unit: z.string().nullish(),
  specimen_date: z.string(),
  timing: z.string().nullish(),
  /** L, H or N against the reference interval. Never computed as LL/HH. */
  abnormal_flag: z.string().nullish(),
  /** null means the test has no target, not that the value passed. */
  on_target: z.boolean().nullish(),
  ref_low: z.union([z.string(), z.number()]).nullish(),
  ref_high: z.union([z.string(), z.number()]).nullish(),
  target_low: z.union([z.string(), z.number()]).nullish(),
  target_high: z.union([z.string(), z.number()]).nullish(),
  source: z.string().nullish(),
}).catchall(z.unknown())

export const labOrderSchema = z.object({
  id: z.number(),
  patient_id: z.number().nullish(),
  ordered_on: z.string(),
  panel: z.string().nullish(),
  status: z.enum(['ordered', 'collected', 'resulted', 'cancelled']),
  collected_at: z.string().nullish(),
  lab_name: z.string().nullish(),
}).catchall(z.unknown())

/** One verdict per row: a mistyped code does not reject the eleven good values. */
export const labFilingSchema = z.object({
  filed: z.number(),
  results: z.array(z.object({
    index: z.number().nullish(),
    test_code: z.string(),
    status: z.enum(['filed', 'duplicate', 'rejected']),
    message: z.string().nullish(),
    abnormal_flag: z.string().nullish(),
    on_target: z.boolean().nullish(),
  }).catchall(z.unknown())),
}).catchall(z.unknown())

/* ---- the treatment lifecycle -------------------------------------------- */

/**
 * What check-in and start answer with: the session, plus two things the plain
 * session schema would silently strip.
 *
 * `z.object()` drops unknown keys, so parsing these responses with
 * treatmentSessionSchema threw away both arrays -- which defeated the point of
 * the server echoing them. An infection-control override is echoed precisely so
 * that "a screen cannot succeed quietly"; a client that discards the echo does
 * exactly that.
 *
 *   cohort_overrides  breaches someone proceeded past with a reason. Already on
 *                     the chart; shown so the person who did it sees it recorded.
 *   machine_warnings  hygiene notes that do not block -- how much has run since
 *                     the machine was last cleaned. The interval is unit policy.
 */
export const lifecycleResultSchema = treatmentSessionSchema.extend({
  cohort_overrides: z.array(z.string()).default([]),
  machine_warnings: z.array(z.string()).default([]),
})

export const machineSchema = z.object({
  id: z.number(),
  asset_tag: z.string(),
  manufacturer: z.string().nullish(),
  model: z.string().nullish(),
  status: z.string(),
  /** Null means the machine may treat any cohort. */
  dedicated_cohort: z.string().nullish(),
  home_station: z.string().nullish(),
}).catchall(z.unknown())

export const machineListSchema = z.object({
  machines: z.array(machineSchema),
}).catchall(z.unknown())

export const availableStationsSchema = z.object({
  date: z.string(),
  cohort: cohortSchema,
  stations: z.array(z.object({ id: z.number(), code: z.string() }).catchall(z.unknown())),
})

export type Token = z.infer<typeof tokenSchema>
export type Patient = z.infer<typeof patientSchema>
export type PatientPage = z.infer<typeof patientPageSchema>
export type Cohort = z.infer<typeof cohortSchema>
export type Health = z.infer<typeof healthSchema>
export type SerologyResult = z.infer<typeof serologyResultSchema>
export type SerologyOutcome = z.infer<typeof serologyOutcomeSchema>
export type TreatmentSession = z.infer<typeof treatmentSessionSchema>
export type SessionPage = z.infer<typeof sessionPageSchema>
export type SessionVital = z.infer<typeof sessionVitalSchema>
export type SessionEvent = z.infer<typeof sessionEventSchema>
export type WaterClearance = z.infer<typeof waterClearanceSchema>
export type WaterDay = z.infer<typeof waterDaySchema>
export type WaterLogResult = z.infer<typeof waterLogResultSchema>
export type WaterLog = z.infer<typeof waterLogSchema>
export type DialyzerUnit = z.infer<typeof dialyzerUnitSchema>
export type DialyzerList = z.infer<typeof dialyzerListSchema>
export type ReprocessResult = z.infer<typeof reprocessResultSchema>
export type BenefitProgram = z.infer<typeof benefitProgramSchema>
export type Claimable = z.infer<typeof claimableSchema>
export type ClaimableGroup = Claimable['groups'][number]
export type ClaimSummary = z.infer<typeof claimSummarySchema>
export type ClaimPage = z.infer<typeof claimPageSchema>
export type ClaimDetail = z.infer<typeof claimDetailSchema>
export type ClaimsOutstanding = z.infer<typeof claimsOutstandingSchema>
export type Invoiceable = z.infer<typeof invoiceableSchema>
export type InvoiceableSession = Invoiceable['sessions'][number]
export type InvoiceSummary = z.infer<typeof invoiceSummarySchema>
export type InvoicePage = z.infer<typeof invoicePageSchema>
export type InvoiceDetail = z.infer<typeof invoiceDetailSchema>
export type Board = z.infer<typeof boardSchema>
export type BoardGeneration = z.infer<typeof boardGenerationSchema>
export type AvailableStations = z.infer<typeof availableStationsSchema>
export type LifecycleResult = z.infer<typeof lifecycleResultSchema>
export type Machine = z.infer<typeof machineSchema>
export type MachineList = z.infer<typeof machineListSchema>
export type LabTest = z.infer<typeof labTestSchema>
export type LabResult = z.infer<typeof labResultSchema>
export type LabOrder = z.infer<typeof labOrderSchema>
export type LabFiling = z.infer<typeof labFilingSchema>
export type Settings = z.infer<typeof settingsSchema>
export type SettingsStation = z.infer<typeof settingsStationSchema>
export type SettingsMedication = z.infer<typeof settingsMedicationSchema>
export type SettingsStaff = z.infer<typeof settingsStaffSchema>
export type SettingsProgram = z.infer<typeof settingsProgramSchema>
export type DryWeights = z.infer<typeof dryWeightSchema>
export type DryWeightSet = z.infer<typeof dryWeightSetSchema>
export type Prescriptions = z.infer<typeof prescriptionsSchema>
export type StandingSchedule = z.infer<typeof standingScheduleSchema>
export type CohortViolationReport = z.infer<typeof cohortViolationReportSchema>
export type WaterExceptionReport = z.infer<typeof waterExceptionReportSchema>
export type DialyzerExceptionReport = z.infer<typeof dialyzerExceptionReportSchema>
export type QualityReport = z.infer<typeof qualityReportSchema>
export type UtilisationReport = z.infer<typeof utilisationReportSchema>

export interface SerologyInput {
  marker: SerologyResult['marker']
  result: SerologyResult['result']
  specimen_date: string
  titre?: number
  resulted_on?: string
  lab_name?: string
}

/** Build a query string, dropping anything undefined or empty. */
function query(params: Record<string, string | number | undefined>): string {
  const pairs = Object.entries(params).filter(
    (entry): entry is [string, string | number] => entry[1] !== undefined && entry[1] !== '',
  )

  if (pairs.length === 0) {
    return ''
  }

  return `?${new URLSearchParams(pairs.map(([key, value]) => [key, String(value)])).toString()}`
}

/* -------------------------------------------------------------------------- */
/* Client                                                                     */
/* -------------------------------------------------------------------------- */

export interface ClientOptions {
  /** Base URL including the version prefix, e.g. https://host/api/v1 */
  baseUrl: string
  /** Identifies this tablet. Tokens are bound to it, and a mismatch revokes them. */
  deviceId: string
  /** Returns the current bearer token, or null when signed out. */
  getToken?: () => string | null
  fetch?: typeof globalThis.fetch
}

/** Thrown for any non-2xx response. Carries the status so 401 can be handled. */
/**
 * The sentence to show a person.
 *
 * A domain refusal from this API is not a status code with a stack trace behind
 * it -- it is a considered explanation of what was refused and why ("Cannot
 * sign, missing: post_weight_kg, ended_at"). Throwing that away and showing
 * "failed with 422" turns the system's most useful behaviour into noise, so the
 * server's own words win whenever it sent any.
 */
function refusalMessage(payload: unknown, method: string, path: string, status: number): string {
  if (payload !== null && typeof payload === 'object') {
    const problem = payload as { message?: unknown; errors?: unknown }

    if (typeof problem.message === 'string' && problem.message.trim() !== '') {
      return problem.message
    }

    // Laravel validation: surface the first field's first complaint rather than
    // the generic envelope around it.
    if (problem.errors !== null && typeof problem.errors === 'object') {
      for (const complaints of Object.values(problem.errors as Record<string, unknown>)) {
        if (Array.isArray(complaints) && typeof complaints[0] === 'string') {
          return complaints[0]
        }
      }
    }
  }

  return `${method} ${path} failed with ${status}`
}

/**
 * An infection-control refusal that a reason can override.
 *
 * The server answers 409 with the breaches and names the field that proceeds
 * past them, so a client never has to guess the way through. Only this refusal
 * carries `override_with`; every other invariant refuses without one, and a
 * screen must not offer an override for those.
 */
export interface CohortRefusal {
  message: string
  violations: string[]
  overrideWith: string
}

export function cohortRefusal(error: unknown): CohortRefusal | null {
  if (!(error instanceof ApiError) || error.status !== 409) return null

  const body = error.body as { message?: unknown; violations?: unknown; override_with?: unknown } | null

  if (body === null || typeof body.override_with !== 'string' || !Array.isArray(body.violations)) {
    return null
  }

  return {
    message: typeof body.message === 'string' ? body.message : error.message,
    violations: body.violations.map(String),
    overrideWith: body.override_with,
  }
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly body: unknown = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /**
   * A 401 during sync must never be read as "this operation failed permanently".
   * The client re-authenticates by PIN and replays the same batch.
   */
  get isAuthFailure(): boolean {
    return this.status === 401
  }
}

export class ApiClient {
  private readonly doFetch: typeof globalThis.fetch

  constructor(private readonly options: ClientOptions) {
    this.doFetch = options.fetch ?? globalThis.fetch.bind(globalThis)
  }

  login(username: string, password: string): Promise<Token> {
    return this.request('POST', '/auth/login', tokenSchema, {
      username,
      password,
      device_id: this.options.deviceId,
    })
  }

  /** Re-issue the token from the 6-digit clinical PIN after the idle lock. */
  pinUnlock(staffPublicId: string, pin: string): Promise<Token> {
    return this.request('POST', '/auth/pin-unlock', tokenSchema, {
      staff_public_id: staffPublicId,
      pin,
      device_id: this.options.deviceId,
    })
  }

  /* ---- registry --------------------------------------------------------- */

  patient(publicId: string): Promise<Patient> {
    return this.request('GET', `/patients/${encodeURIComponent(publicId)}`, patientSchema)
  }

  patients(params: { search?: string; status?: string; page?: number } = {}): Promise<PatientPage> {
    return this.request('GET', `/patients${query(params)}`, patientPageSchema)
  }

  registerPatient(attributes: Record<string, unknown>): Promise<Patient> {
    return this.request('POST', '/patients', patientSchema, attributes)
  }

  updatePatient(publicId: string, attributes: Record<string, unknown>): Promise<Patient> {
    return this.request('PATCH', `/patients/${encodeURIComponent(publicId)}`, patientSchema, attributes)
  }

  /**
   * Status changes have their own endpoint because the server writes a history
   * row alongside the column. Sending `status` to updatePatient is ignored.
   */
  changePatientStatus(
    publicId: string,
    change: { status: string; effective_on: string; reason?: string; destination?: string },
  ): Promise<Patient> {
    return this.request('POST', `/patients/${encodeURIComponent(publicId)}/status`, patientSchema, change)
  }

  /* ---- serology and cohort ---------------------------------------------- */

  serology(publicId: string): Promise<SerologyResult[]> {
    return this.request('GET', `/patients/${encodeURIComponent(publicId)}/serology`, serologyListSchema)
  }

  /**
   * File a serology result.
   *
   * Check `cohort_changed` on the response: a reactive HBsAg moves the patient
   * between infection-control cohorts, and `affected_sessions` then lists the
   * bookings that are now in a chair the new cohort does not permit. Nothing is
   * blocked server-side, so this is the moment a human has to be told.
   */
  recordSerology(publicId: string, result: SerologyInput): Promise<SerologyOutcome> {
    return this.request(
      'POST',
      `/patients/${encodeURIComponent(publicId)}/serology`,
      serologyOutcomeSchema,
      result,
    )
  }

  /* ---- the session lifecycle --------------------------------------------- */

  sessions(params: { date?: string; status?: string; page?: number } = {}): Promise<SessionPage> {
    return this.request('GET', `/sessions${query(params)}`, sessionPageSchema)
  }

  session(publicId: string): Promise<TreatmentSession> {
    return this.request('GET', `/sessions/${encodeURIComponent(publicId)}`, treatmentSessionSchema)
  }

  /** Keeps cohort_overrides and machine_warnings -- see lifecycleResultSchema. */
  checkIn(publicId: string, observations: Record<string, unknown>): Promise<LifecycleResult> {
    return this.request('POST', `/sessions/${encodeURIComponent(publicId)}/check-in`, lifecycleResultSchema, observations)
  }

  /**
   * Keeps cohort_overrides and machine_warnings. Name a dialyzer by
   * `dialyzer_label_code` -- the unit's id is never exposed to a client.
   */
  startSession(publicId: string, settings: Record<string, unknown> = {}): Promise<LifecycleResult> {
    return this.request('POST', `/sessions/${encodeURIComponent(publicId)}/start`, lifecycleResultSchema, settings)
  }

  endSession(publicId: string, outcome: Record<string, unknown>): Promise<TreatmentSession> {
    return this.session_(publicId, 'end', outcome)
  }

  /* ---- the flow sheet ------------------------------------------------------ */

  vitals(publicId: string): Promise<SessionVital[]> {
    return this.request('GET', `/sessions/${encodeURIComponent(publicId)}/vitals`, z.array(sessionVitalSchema))
  }

  /**
   * Append one observation.
   *
   * Append-only, keyed by (session, recorded_at). A retry lands on 409 rather
   * than creating a second reading, and a 409 here means "already recorded" --
   * not a failure the caller has to recover from.
   */
  appendVital(publicId: string, vital: Record<string, unknown>): Promise<SessionVital> {
    return this.request(
      'POST', `/sessions/${encodeURIComponent(publicId)}/vitals`, sessionVitalSchema, vital,
    )
  }

  events(publicId: string): Promise<SessionEvent[]> {
    return this.request('GET', `/sessions/${encodeURIComponent(publicId)}/events`, z.array(sessionEventSchema))
  }

  appendEvent(publicId: string, event: Record<string, unknown>): Promise<SessionEvent> {
    return this.request(
      'POST', `/sessions/${encodeURIComponent(publicId)}/events`, sessionEventSchema, event,
    )
  }

  /* ---- attestation --------------------------------------------------------- */

  signAsNurse(publicId: string): Promise<TreatmentSession> {
    return this.session_(publicId, 'sign/nurse', {})
  }

  signAsPhysician(publicId: string): Promise<TreatmentSession> {
    return this.session_(publicId, 'sign/physician', {})
  }

  /** The only way into a signed record. The reason becomes part of the chart. */
  amendSession(
    publicId: string,
    reason: string,
    changes: Record<string, unknown>,
  ): Promise<TreatmentSession> {
    return this.session_(publicId, 'amend', { reason, changes })
  }

  /* ---- billing -------------------------------------------------------------- */

  /** The claims list, newest service first. `q` matches a claim number, MRN or name. */
  claims(params: { status?: string; q?: string; page?: number } = {}): Promise<ClaimPage> {
    return this.request('GET', `/claims${query(params)}`, claimPageSchema)
  }

  /**
   * What can still be claimed for a patient, grouped by program and period,
   * with the rate and the allotment each group will actually be charged
   * against. No lower date bound unless `from` is given.
   */
  claimable(patientPublicId: string, from?: string, to?: string): Promise<Claimable> {
    return this.request(
      'GET', `/patients/${encodeURIComponent(patientPublicId)}/claimable${query({ from, to })}`, claimableSchema,
    )
  }

  /**
   * Build one claim over named sessions, by public_id.
   *
   * All or nothing: if any session is already claimed, unsigned, another
   * patient's, or under a different program or period from the first, the
   * whole call is refused and nothing is written.
   */
  generateClaim(patientPublicId: string, sessionPublicIds: string[]): Promise<ClaimDetail> {
    return this.request(
      'POST', `/patients/${encodeURIComponent(patientPublicId)}/claims`, claimDetailSchema,
      { session_public_ids: sessionPublicIds },
    )
  }

  claim(claimNo: string): Promise<ClaimDetail> {
    return this.request('GET', `/claims/${encodeURIComponent(claimNo)}`, claimDetailSchema)
  }

  /**
   * Move a claim along one of its `next_statuses`.
   *
   * A denial or a return needs remarks, and a void needs ten characters of
   * them: voiding frees the claim's sessions to be claimed again.
   */
  transitionClaim(claimNo: string, status: string, remarks?: string): Promise<ClaimDetail> {
    return this.request(
      'POST', `/claims/${encodeURIComponent(claimNo)}/status`, claimDetailSchema, { status, remarks },
    )
  }

  /** The payer's remittance advice. The status follows from the amounts; it is never picked. */
  recordRemittance(claimNo: string, advice: RemittanceInput): Promise<ClaimDetail> {
    return this.request(
      'POST', `/claims/${encodeURIComponent(claimNo)}/remittance`, claimDetailSchema, advice,
    )
  }

  /** What the unit is owed across claims: approved less paid. */
  claimsOutstanding(from?: string, to?: string): Promise<ClaimsOutstanding> {
    return this.request('GET', `/claims-outstanding${query({ from, to })}`, claimsOutstandingSchema)
  }

  /** The invoices list, newest first. `q` matches an invoice number, MRN or name. */
  invoices(params: { status?: string; q?: string; page?: number } = {}): Promise<InvoicePage> {
    return this.request('GET', `/invoices${query(params)}`, invoicePageSchema)
  }

  /** Sessions that could go on a new invoice, with the claim each is on and its list price. */
  invoiceable(patientPublicId: string, from?: string, to?: string): Promise<Invoiceable> {
    return this.request(
      'GET', `/patients/${encodeURIComponent(patientPublicId)}/invoiceable${query({ from, to })}`, invoiceableSchema,
    )
  }

  /** Draft an invoice over named sessions. The server prices it; nothing here adds money up. */
  draftInvoice(patientPublicId: string, sessionPublicIds: string[]): Promise<InvoiceDetail> {
    return this.request(
      'POST', `/patients/${encodeURIComponent(patientPublicId)}/invoices`, invoiceDetailSchema,
      { session_public_ids: sessionPublicIds },
    )
  }

  invoice(invoiceNo: string): Promise<InvoiceDetail> {
    return this.request('GET', `/invoices/${encodeURIComponent(invoiceNo)}`, invoiceDetailSchema)
  }

  issueInvoice(invoiceNo: string): Promise<InvoiceDetail> {
    return this.request('POST', `/invoices/${encodeURIComponent(invoiceNo)}/issue`, invoiceDetailSchema, {})
  }

  /** Refused while any money sits on the invoice; the reason goes on the invoice and into audit_logs. */
  voidInvoice(invoiceNo: string, reason: string): Promise<InvoiceDetail> {
    return this.request(
      'POST', `/invoices/${encodeURIComponent(invoiceNo)}/void`, invoiceDetailSchema, { reason },
    )
  }

  /** A negative amount is a refund. The server refuses more than is owed, or a refund of more than was received. */
  recordPayment(invoiceNo: string, payment: PaymentInput): Promise<InvoiceDetail> {
    return this.request(
      'POST', `/invoices/${encodeURIComponent(invoiceNo)}/payments`, invoiceDetailSchema, payment,
    )
  }

  /* ---- ops: water and dialyzer reuse -------------------------------------- */

  /**
   * The day's water clearance.
   *
   * Worth reading before the morning shift: `cleared: false` means the first
   * treatment of the day will be refused until a passing check is logged.
   */
  water(date?: string): Promise<WaterDay> {
    return this.request('GET', `/water-logs${query({ date })}`, waterDaySchema)
  }

  recordWaterCheck(check: WaterCheckInput): Promise<WaterLogResult> {
    return this.request('POST', '/water-logs', waterLogResultSchema, check)
  }

  /** A patient's dialyzers, each with the reason it cannot be issued, if any. */
  machines(status?: string): Promise<MachineList> {
    return this.request('GET', `/machines${query({ status })}`, machineListSchema)
  }

  dialyzers(patientPublicId: string): Promise<DialyzerList> {
    return this.request(
      'GET', `/patients/${encodeURIComponent(patientPublicId)}/dialyzers`, dialyzerListSchema,
    )
  }

  /** Record a reprocessing cycle. The unit may be condemned by this call. */
  reprocessDialyzer(unitId: number, cycle: Record<string, unknown>): Promise<ReprocessResult> {
    return this.request('POST', `/dialyzers/${unitId}/reprocess`, reprocessResultSchema, cycle)
  }

  /* ---- scheduling -------------------------------------------------------- */

  board(date: string, shift?: string): Promise<Board> {
    return this.request('GET', `/board${query({ date, shift })}`, boardSchema)
  }

  /** Idempotent: safe to call again after adding a patient to the day. */
  generateBoard(date: string): Promise<BoardGeneration> {
    return this.request('POST', '/board/generate', boardGenerationSchema, { date })
  }

  availableStations(publicId: string, date: string, shiftId: number): Promise<AvailableStations> {
    return this.request(
      'GET',
      `/patients/${encodeURIComponent(publicId)}/available-stations${query({ date, shift_id: shiftId })}`,
      availableStationsSchema,
    )
  }

  /* ---- the patient's effective-dated histories --------------------------- */

  dryWeights(publicId: string): Promise<DryWeights> {
    return this.request('GET', `/patients/${publicId}/dry-weights`, dryWeightSchema)
  }

  /** Answers with the new current value only; re-read the history if you need it. */
  setDryWeight(publicId: string, weightKg: string, effectiveFrom: string, reason?: string): Promise<DryWeightSet> {
    return this.request('POST', `/patients/${publicId}/dry-weights`, dryWeightSetSchema, {
      weight_kg: weightKg,
      effective_from: effectiveFrom,
      reason,
    })
  }

  prescriptions(publicId: string): Promise<Prescriptions> {
    return this.request('GET', `/patients/${publicId}/prescriptions`, prescriptionsSchema)
  }

  standingSchedule(publicId: string): Promise<StandingSchedule> {
    return this.request('GET', `/patients/${publicId}/schedule`, standingScheduleSchema)
  }

  /* ---- labs -------------------------------------------------------------- */

  labTests(panel?: string): Promise<{ tests: LabTest[] }> {
    return this.request('GET', `/lab-tests${query({ panel })}`, z.object({ tests: z.array(labTestSchema) }))
  }

  labOrders(publicId: string): Promise<{ orders: LabOrder[] }> {
    return this.request('GET', `/patients/${publicId}/lab-orders`,
      z.object({ orders: z.array(labOrderSchema) }))
  }

  orderLabPanel(publicId: string, panel: string, labName?: string): Promise<{ order: LabOrder }> {
    return this.request('POST', `/patients/${publicId}/lab-orders`,
      z.object({ order: labOrderSchema }), { panel, lab_name: labName })
  }

  transitionLabOrder(orderId: number, status: string): Promise<{ order: LabOrder }> {
    return this.request('POST', `/lab-orders/${orderId}/status`,
      z.object({ order: labOrderSchema }), { status })
  }

  /** `latest` gives the current value per test; without it, the full history. */
  labResults(publicId: string, options: { latest?: boolean; testCode?: string } = {}): Promise<{ results: LabResult[] }> {
    return this.request(
      'GET',
      `/patients/${publicId}/lab-results${query({
        latest: options.latest === true ? '1' : undefined,
        test_code: options.testCode,
      })}`,
      z.object({ results: z.array(labResultSchema) }),
    )
  }

  /**
   * File a panel. There is deliberately no abnormal_flag field -- the server
   * derives it from the reference interval, so a client cannot store a value and
   * a flag that contradict each other.
   */
  fileLabResults(
    publicId: string,
    results: Record<string, unknown>[],
    orderId?: number,
  ): Promise<LabFiling> {
    return this.request('POST', `/patients/${publicId}/lab-results`, labFilingSchema, {
      order_id: orderId,
      results,
    })
  }

  /* ---- unit settings ----------------------------------------------------- */

  settings(): Promise<Settings> {
    return this.request('GET', '/settings', settingsSchema)
  }

  updateFacility(attributes: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request('PATCH', '/settings/facility', z.object({}).catchall(z.unknown()), attributes)
  }

  /**
   * An empty list makes the chair unrestricted. That is allowed, and audited.
   *
   * `reason` lands in audit_logs.reason -- the server reads `_audit_reason` off
   * the request body for exactly this. Worth supplying whenever the change
   * loosens a rule rather than tightens one.
   */
  setStationCohorts(stationId: number, cohorts: string[], reason?: string): Promise<{ stations: SettingsStation[] }> {
    return this.request('PUT', `/settings/stations/${stationId}/cohorts`,
      z.object({ stations: z.array(settingsStationSchema) }), { cohorts, _audit_reason: reason })
  }

  addBenefitProgram(program: Record<string, unknown>): Promise<{ benefit_programs: SettingsProgram[] }> {
    return this.request('POST', '/settings/benefit-programs',
      z.object({ benefit_programs: z.array(settingsProgramSchema) }), program)
  }

  setHighAlert(medicationId: number, isHighAlert: boolean, reason?: string): Promise<{ high_alert: SettingsMedication[] }> {
    return this.request('PATCH', `/settings/medications/${medicationId}/high-alert`,
      z.object({ high_alert: z.array(settingsMedicationSchema) }),
      { is_high_alert: isHighAlert, _audit_reason: reason })
  }

  setStaffRoles(staffPublicId: string, roles: string[]): Promise<{ staff: SettingsStaff[] }> {
    return this.request('PUT', `/settings/staff/${staffPublicId}/roles`,
      z.object({ staff: z.array(settingsStaffSchema) }), { roles })
  }

  /* ---- reports ---------------------------------------------------------- */

  cohortViolations(from?: string): Promise<CohortViolationReport> {
    return this.request('GET', `/reports/cohort-violations${query({ from })}`, cohortViolationReportSchema)
  }

  waterExceptions(): Promise<WaterExceptionReport> {
    return this.request('GET', '/reports/water-exceptions', waterExceptionReportSchema)
  }

  dialyzerExceptions(): Promise<DialyzerExceptionReport> {
    return this.request('GET', '/reports/dialyzer-exceptions', dialyzerExceptionReportSchema)
  }

  quality(month?: string, live = false): Promise<QualityReport> {
    return this.request('GET', `/reports/quality${query({ month, live: live ? '1' : undefined })}`, qualityReportSchema)
  }

  utilisation(from?: string, to?: string): Promise<UtilisationReport> {
    return this.request('GET', `/reports/utilisation${query({ from, to })}`, utilisationReportSchema)
  }

  health(): Promise<Health> {
    return this.request('GET', '/health', healthSchema)
  }

  /** Every session action is a POST to a sub-path returning the session. */
  private session_(publicId: string, action: string, body: unknown): Promise<TreatmentSession> {
    return this.request(
      'POST',
      `/sessions/${encodeURIComponent(publicId)}/${action}`,
      treatmentSessionSchema,
      body,
    )
  }

  private async request<T>(
    method: string,
    path: string,
    schema: z.ZodType<T>,
    body?: unknown,
  ): Promise<T> {
    const token = this.options.getToken?.() ?? null

    const headers: Record<string, string> = {
      Accept: 'application/json',
      // The token is bound to this device. Sending it on every request is what
      // lets the server detect and revoke a copied credential.
      'X-Device-Id': this.options.deviceId,
    }

    if (body !== undefined) {
      headers['Content-Type'] = 'application/json'
    }

    if (token !== null) {
      headers.Authorization = `Bearer ${token}`
    }

    const response = await this.doFetch(`${this.options.baseUrl}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })

    const payload: unknown = await response.json().catch(() => null)

    if (!response.ok) {
      throw new ApiError(response.status, refusalMessage(payload, method, path, response.status), payload)
    }

    return schema.parse(payload)
  }
}
