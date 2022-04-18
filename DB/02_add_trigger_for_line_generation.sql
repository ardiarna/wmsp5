CREATE OR REPLACE FUNCTION update_lines()
  RETURNS TRIGGER AS
$BODY$
DECLARE
  account_type VARCHAR;
  counter      INTEGER := 1;
  iterator     INTEGER :=0;
BEGIN
  IF (TG_OP = 'INSERT')
  THEN

    iterator := NEW.kd_baris;
    WHILE counter <= iterator LOOP
      IF (counter < 10)
      THEN
        INSERT INTO inv_master_lok_pallet (iml_plan_kode, iml_kd_area, iml_no_lok, iml_kd_lok)
        VALUES (NEW.plan_kode, NEW.kd_area, counter, NEW.plan_kode || NEW.kd_area || '00' || (counter));
      ELSIF (counter < 100)
        THEN
          INSERT INTO inv_master_lok_pallet (iml_plan_kode, iml_kd_area, iml_no_lok, iml_kd_lok)
          VALUES (NEW.plan_kode, NEW.kd_area, counter, NEW.plan_kode || NEW.kd_area || '0' || (counter));
      ELSE
        INSERT INTO inv_master_lok_pallet (iml_plan_kode, iml_kd_area, iml_no_lok, iml_kd_lok)
        VALUES (NEW.plan_kode, NEW.kd_area, counter, NEW.plan_kode || NEW.kd_area || (counter));
      END IF;
      counter := counter + 1;

    END LOOP;

    RETURN NEW;
  ELSIF (TG_OP = 'UPDATE')
    THEN
      IF (NEW.kd_baris < OLD.kd_baris)
      THEN
        RAISE EXCEPTION 'Baris Cannot be less than existing Baris';
      ELSE
        iterator := NEW.kd_baris - OLD.kd_baris;
        WHILE counter <= iterator LOOP
          IF (OLD.kd_baris + counter < 10)
          THEN
            INSERT INTO inv_master_lok_pallet VALUES (OLD.plan_kode, OLD.kd_area, OLD.kd_baris + counter,
                                                      OLD.plan_kode || OLD.kd_area || '00' || (OLD.kd_baris + counter));
          ELSIF (counter < 100)
            THEN
              INSERT INTO inv_master_lok_pallet VALUES (OLD.plan_kode, OLD.kd_area, OLD.kd_baris + counter,
                                                        OLD.plan_kode || OLD.kd_area || '0' ||
                                                        (OLD.kd_baris + counter));
          ELSE
            INSERT INTO inv_master_lok_pallet VALUES (OLD.plan_kode, OLD.kd_area, OLD.kd_baris + counter,
                                                      OLD.plan_kode || OLD.kd_area || (OLD.kd_baris + counter));
          END IF;
          counter := counter + 1;

        END LOOP;
      END IF;
      RETURN NEW;

  ELSIF (TG_OP = 'DELETE')
    THEN
      RAISE EXCEPTION 'No Deletion Allowed';

  END IF;

  RETURN NULL;
END;
$BODY$
LANGUAGE plpgsql
VOLATILE
COST 100;


CREATE TRIGGER update_line_trigger
  BEFORE UPDATE OR DELETE
  ON inv_master_area
  FOR EACH ROW
EXECUTE PROCEDURE update_lines();

CREATE TRIGGER add_line_trigger
  AFTER INSERT
  ON inv_master_area
  FOR EACH ROW
EXECUTE PROCEDURE update_lines();
