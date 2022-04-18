CREATE OR REPLACE VIEW pallets_with_location AS
  SELECT tbl_sp_hasilbj.*,
         inv_master_area.plan_kode         AS location_subplant,
         inv_opname.io_kd_lok              AS location_no,
         inv_opname.io_tgl                 AS location_since,
         inv_master_lok_pallet.iml_kd_area AS location_area_no,
         inv_master_area.ket_area          AS location_area_name,
         inv_master_lok_pallet.iml_no_lok  AS location_row_no,
         inv_master_lok_pallet.iml_kd_lok  AS location_id,
         inv_opname.io_qty_pallet          AS block_status
  FROM inv_opname
         INNER JOIN tbl_sp_hasilbj ON tbl_sp_hasilbj.pallet_no = inv_opname.io_no_pallet
         INNER JOIN inv_master_lok_pallet ON inv_opname.io_kd_lok = inv_master_lok_pallet.iml_kd_lok
                                               AND inv_opname.io_plan_kode = inv_master_lok_pallet.iml_plan_kode
         INNER JOIN inv_master_area ON inv_master_lok_pallet.iml_kd_area = inv_master_area.kd_area;

CREATE OR REPLACE VIEW summary_pallets_with_location_by_area AS
  SELECT pallets_with_location.location_subplant  AS location_subplant,
         pallets_with_location.location_area_name AS location_area_name,
         pallets_with_location.location_area_no   AS location_area_no,
         COUNT(pallets_with_location.pallet_no)   AS pallet_count,
         SUM(pallets_with_location.last_qty)      AS current_quantity
  FROM pallets_with_location
         INNER JOIN item ON pallets_with_location.item_kode = item.item_kode
  WHERE last_qty > 0
  GROUP BY pallets_with_location.location_subplant, location_area_name, location_area_no
  ORDER BY location_subplant, location_area_no, location_area_name;

CREATE OR REPLACE VIEW summary_pallets_with_location_by_area_row AS
  SELECT pallets_with_location.location_subplant                                                          AS location_subplant,
         pallets_with_location.location_area_name                                                         AS location_area_name,
         pallets_with_location.location_area_no                                                           AS location_area_no,
         pallets_with_location.location_row_no                                                            AS location_row_no,
         pallets_with_location.location_id                                                                AS location_id,
         item.item_kode                                                                                   AS motif_id,
         item.item_nama                                                                                   AS motif_name,
         (SELECT category_nama
          FROM category
          WHERE category.category_kode = substr(item.item_kode, 1, 2))                                    AS motif_dimension,
         pallets_with_location.size                                                                       AS size,
         pallets_with_location.shade                                                                      AS shading,
         COUNT(pallets_with_location.pallet_no)                                                           AS pallet_count,
         SUM(pallets_with_location.last_qty)                                                              AS current_quantity
  FROM pallets_with_location
         INNER JOIN item ON pallets_with_location.item_kode = item.item_kode
  WHERE last_qty > 0
  GROUP BY location_subplant, location_area_name, location_area_no, location_row_no, location_id, motif_id,
           motif_name, motif_dimension, size, shading
  ORDER BY location_subplant, location_area_no, location_area_name;

CREATE OR REPLACE VIEW summary_pallets_with_location_by_motif AS
  SELECT pallets_with_location.location_subplant                                                          AS location_subplant,
         pallets_with_location.quality                                                                    AS quality,
         item.item_kode                                                                                   AS motif_id,
         item.item_nama                                                                                   AS motif_name,
         (SELECT category_nama
          FROM category
          WHERE category.category_kode = substr(item.item_kode, 1, 2))                                    AS motif_dimension,
         COUNT(pallets_with_location.pallet_no)                                                           AS pallet_count,
         SUM(pallets_with_location.last_qty)                                                              AS current_quantity
  FROM pallets_with_location
         INNER JOIN item ON pallets_with_location.item_kode = item.item_kode
  WHERE last_qty > 0
  GROUP BY pallets_with_location.location_subplant, pallets_with_location.quality, motif_id, motif_name,
           motif_dimension;

CREATE OR REPLACE VIEW summary_pallets_with_location_by_motif_size_shading AS
  SELECT pallets_with_location.location_subplant                                                          AS location_subplant,
         item.item_kode                                                                                   AS motif_id,
         item.item_nama                                                                                   AS motif_name,
         (SELECT category_nama
          FROM category
          WHERE category.category_kode = substr(item.item_kode, 1, 2))                                    AS motif_dimension,
         pallets_with_location.size                                                                       AS size,
         pallets_with_location.shade                                                                      AS shading,
         COUNT(pallets_with_location.pallet_no)                                                           AS pallet_count,
         SUM(pallets_with_location.last_qty)                                                              AS current_quantity
  FROM pallets_with_location
         INNER JOIN item ON pallets_with_location.item_kode = item.item_kode
  WHERE last_qty > 0
  GROUP BY pallets_with_location.location_subplant, motif_id, motif_name, motif_dimension, size, shading;

