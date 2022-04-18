CREATE TABLE IF NOT EXISTS meta_mv_refresh (
  mv_name            VARCHAR(63) NOT NULL PRIMARY KEY,
  mv_last_updated_at TIMESTAMP   NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION mv_refresh(mv_id REGCLASS, concurrent BOOLEAN DEFAULT FALSE)
  RETURNS TIMESTAMP AS $$
DECLARE
  query_str TEXT;
BEGIN
  query_str := 'REFRESH MATERIALIZED VIEW ';
  IF concurrent
  THEN
    query_str := query_str || ' CONCURRENTLY ';
  END IF;
  EXECUTE query_str || mv_id;

  IF EXISTS(SELECT * FROM meta_mv_refresh WHERE mv_name = mv_id::TEXT)
  THEN
    UPDATE meta_mv_refresh SET mv_last_updated_at = CURRENT_TIMESTAMP WHERE mv_name = mv_id::TEXT;
  ELSE
    INSERT INTO meta_mv_refresh VALUES (mv_id::TEXT);
  END IF;
  RETURN CURRENT_TIMESTAMP;
END;
$$
LANGUAGE plpgsql;

COMMENT ON FUNCTION mv_refresh(REGCLASS, BOOLEAN) IS 'Updates a materialized view and stores the update time to "meta_mv_update".'
