ALTER TABLE inv_master_area
  ADD COLUMN kd_baris NUMERIC;
ALTER TABLE inv_master_area
  ADD COLUMN area_status BOOLEAN DEFAULT TRUE;
ALTER TABLE inv_opname
  ALTER COLUMN io_plan_kode TYPE VARCHAR(2);
ALTER TABLE inv_master_area
  ALTER COLUMN plan_kode TYPE VARCHAR(2);
ALTER TABLE inv_opname_hist
  ALTER COLUMN ioh_plan_kode TYPE VARCHAR(2);
ALTER TABLE inv_master_lok_pallet
  ALTER COLUMN iml_plan_kode TYPE VARCHAR(2);
ALTER TABLE inv_master_lok_pallet
  ALTER COLUMN iml_kd_area TYPE VARCHAR(3);
ALTER TABLE inv_opname
  ALTER COLUMN io_kd_lok TYPE VARCHAR(8);
ALTER TABLE inv_opname_hist
  ALTER COLUMN ioh_kd_lok TYPE VARCHAR(8);
ALTER TABLE inv_master_area
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE inv_master_area
  ADD COLUMN updated_by VARCHAR(20) NULL;
ALTER TABLE inv_master_lok_pallet
  DROP COLUMN IF EXISTS iml_no_baris; -- TODO: do NOT use this in P1 or P2!
ALTER TABLE inv_master_area
  ADD COLUMN remarks TEXT NOT NULL DEFAULT '';

-- dropping unused columns, for P3, P4, and P5
ALTER TABLE inv_master_area
  DROP COLUMN IF EXISTS jum_brs,
  DROP COLUMN IF EXISTS jum_plt;
