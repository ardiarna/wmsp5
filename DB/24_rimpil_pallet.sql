CREATE VIEW pallets_with_location_rimpil AS
  SELECT '2' || ima.plan_kode AS location_subplant,
         ima.ket_area         AS location_area_name,
         ima.kd_area          AS location_area_no,
         iml.iml_no_baris     AS location_line_no,
         iml.iml_no_lok       AS location_cell_no,
         io.io_kd_lok         AS location_id,
         hasilbj.pallet_no    AS pallet_no,
         hasilbj.subplant     AS production_subplant,
         hasilbj.item_kode    AS motif_id,
         t3.motif_dimension   AS motif_dimension,
         t3.motif_name        AS motif_name,
         t3.quality           AS quality,
         hasilbj.size         AS size,
         hasilbj.shade        AS shading,
         hasilbj.create_date  AS creation_date,
         hasilbj.regu         AS creator_group,
         hasilbj.shift        AS creator_shift,
         hasilbj.line         AS line,
         hasilbj.last_qty     AS current_quantity,
         t3.is_rimpil         AS is_rimpil
  FROM tbl_sp_hasilbj hasilbj
         INNER JOIN inv_opname io ON hasilbj.pallet_no = io.io_no_pallet
         INNER JOIN inv_master_lok_pallet iml ON io.io_kd_lok = iml.iml_kd_lok
         INNER JOIN inv_master_area ima ON iml.iml_plan_kode = ima.plan_kode AND iml.iml_kd_area = ima.kd_area
         INNER JOIN (
                    -- find rimpils, based on stock with location
                    SELECT production_subplant,
                           motif_id,
                           motif_dimension,
                           motif_name,
                           quality,
                           size,
                           shading,

                           -- rimpil definition: SKU (motif-quality-size-shading) that have quantity no greater than 2 pallets.
                           (CASE
                              WHEN (motif_dimension = '40 X 40' AND current_quantity <= 156)
                                     OR (motif_dimension = '25 X 40' AND current_quantity <= 198)
                                     OR (motif_dimension = '20 X 25' AND current_quantity <= 196)
                                     OR (motif_dimension = '25 X 50' AND current_quantity <= 168)
                                     OR (motif_dimension = '50 X 50' AND current_quantity <= 176)
                                     OR (motif_dimension = '20 X 20' AND current_quantity <= 200)
                                     OR (motif_dimension = '25 X 25' AND current_quantity <= 196)
                                     OR (motif_dimension = '30 X 30' AND current_quantity <= 198)
                                      THEN TRUE
                              ELSE FALSE END) AS is_rimpil
                    FROM (SELECT hasilbj.subplant       AS production_subplant,
                                 category.category_nama AS motif_dimension,
                                 item.item_kode         AS motif_id,
                                 item.item_nama         AS motif_name,
                                 item.quality           AS quality,
                                 hasilbj.size           AS size,
                                 hasilbj.shade          AS shading,
                                 SUM(last_qty)          AS current_quantity
                          FROM tbl_sp_hasilbj hasilbj
                                 INNER JOIN inv_opname io ON hasilbj.pallet_no = io.io_no_pallet
                                 INNER JOIN item ON hasilbj.item_kode = item.item_kode
                                 INNER JOIN category ON SUBSTR(item.item_kode, 1, 2) = category.category_kode
                          WHERE hasilbj.last_qty > 0
                            AND io.io_kd_lok IS NOT NULL
                            AND hasilbj.status_plt = 'R'
                          GROUP BY production_subplant, motif_dimension, motif_id, item.quality, size, shading) t2) t3
           ON hasilbj.subplant = t3.production_subplant AND hasilbj.item_kode = t3.motif_id AND
              hasilbj.size = t3.size AND hasilbj.shade = t3.shading
  WHERE hasilbj.last_qty > 0
  ORDER BY production_subplant, pallet_no;
