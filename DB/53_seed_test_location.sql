TRUNCATE TABLE inv_opname_hist, inv_opname, inv_master_lok_pallet, inv_master_area;
-- prepare test areas
INSERT INTO inv_master_area (plan_kode, kd_area, ket_area, kd_baris, remarks,updated_by,area_status ) VALUES
  ('04', 'T01', 'Test Area 1', (SELECT floor(random() * (1000 - 1 + 1) + 1)), 'Generated for Testing', 'admin','t'),
  ('04', 'T02', 'Test Area 2', (SELECT floor(random() * (250 - 1 + 1) + 1)), 'Generated for Testing', 'admin','f'),
  ('04', 'T03', 'Test Area 3', (SELECT floor(random() * (300 - 1 + 1) + 1)), 'Generated for Testing', 'admin','t'),
  ('04', 'U01', 'Test Area 1', (SELECT floor(random() * (200 - 1 + 1) + 1)), 'Generated for Testing', 'admin','f'),
  ('04', 'U02', 'Test Area 2', (SELECT floor(random() * (100 - 1 + 1) + 1)), 'Generated for Testing', 'admin','t'),
  ('04', 'U03', 'Test Area 3', (SELECT floor(random() * (40 - 1 + 1) + 1)), 'Generated for Testing', 'admin','t'),
  ('04', 'V01', 'Test Area 1', (SELECT floor(random() * (10 - 1 + 1) + 1)), 'Generated for Testing', 'admin','t'),
  ('04', 'V02', 'Test Area 2', (SELECT floor(random() * (100 - 1 + 1) + 1)), 'Generated for Testing', 'admin','t'),
  ('04', 'V03', 'Test Area 3', (SELECT floor(random() * (30 - 1 + 1) + 1)), 'Generated for Testing', 'admin','t');
-- location numbers (i.e. location id) should be generated automatically

-- only works in >= 9.0
-- assigns pallets to randomly-generated locations.
DO $$
DECLARE
  pallet_nos          VARCHAR [];
  location_count      INT;
  pallet_nos_taken    BOOLEAN [];
  no_of_elems_to_take INT;
  idx                 INT;
  location_no         VARCHAR;
  subplant            VARCHAR;
BEGIN
  -- select all pallets with last_qty > 0 from March 2018, which has remaining quantity and has been handed over

  pallet_nos := (SELECT array_agg(pallet_no)
                 FROM tbl_sp_hasilbj
                 WHERE create_date >= '2018-03-01'
                       AND status_plt <> 'C'
                       AND terima_no IS NOT NULL
                       AND last_qty > 0);
  CREATE TEMPORARY TABLE temp_location ON COMMIT DROP AS
    SELECT
      iml_plan_kode,
      iml_kd_lok
    FROM inv_master_lok_pallet;
  location_count := (SELECT COUNT(*)
                     FROM temp_location);

  no_of_elems_to_take := ceil(array_length(pallet_nos, 1) * 0.7) :: INT; -- take 70% of elements.
  pallet_nos_taken := ARRAY(SELECT FALSE
                            FROM generate_series(1, no_of_elems_to_take, 1));

  FOR i IN 1..no_of_elems_to_take LOOP
    idx := ceil(random() * no_of_elems_to_take) :: INT;
    WHILE pallet_nos_taken [idx] IS TRUE LOOP
      idx := ceil(random() * no_of_elems_to_take) :: INT;
    END LOOP;
    pallet_nos_taken [idx] := TRUE;

    -- insert into random location
    SELECT
      iml_kd_lok,
      iml_plan_kode
    INTO location_no, subplant
    FROM temp_location
    ORDER BY RANDOM()
    LIMIT 1;
    INSERT INTO inv_opname (io_plan_kode, io_kd_lok, io_no_pallet, io_qty_pallet, io_tgl)
    VALUES (subplant, location_no, pallet_nos [idx], 0, CURRENT_TIMESTAMP);
    INSERT INTO inv_opname_hist (ioh_plan_kode, ioh_kd_lok, ioh_no_pallet, ioh_qty_pallet, ioh_tgl, ioh_txn, ioh_userid)
    VALUES (subplant, location_no, pallet_nos [idx], 0, CURRENT_TIMESTAMP, 'TEST SEED', 'admin');
  END LOOP;

  DROP TABLE temp_location;
END$$;
