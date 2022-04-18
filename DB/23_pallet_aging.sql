CREATE MATERIALIZED VIEW pallets_with_location_age_and_rimpil AS
  SELECT ima.plan_kode                      AS location_subplant,
         ima.ket_area                       AS location_area_name,
         ima.kd_area                        AS location_area_no,
         iml.iml_no_lok                     AS location_line_no,
         io.io_kd_lok                       AS location_id,
         hasilbj.pallet_no                  AS pallet_no,
         hasilbj.subplant                   AS production_subplant,
         hasilbj.item_kode                  AS motif_id,
         t3.motif_dimension                 AS motif_dimension,
         t3.motif_name                      AS motif_name,
         t3.quality                         AS quality,
         hasilbj.size                       AS size,
         hasilbj.shade                      AS shading,
         hasilbj.create_date                AS creation_date,
         hasilbj.regu                       AS creator_group,
         hasilbj.shift                      AS creator_shift,
         hasilbj.line                       AS line,
         hasilbj.last_qty                   AS current_quantity,
         CURRENT_DATE - hasilbj.create_date AS pallet_age,
         (CASE
            WHEN CURRENT_DATE - hasilbj.create_date <= 5 THEN 'Very Fast'
            WHEN CURRENT_DATE - hasilbj.create_date > 5 AND CURRENT_DATE - hasilbj.create_date <= 60 THEN 'Fast'
            WHEN CURRENT_DATE - hasilbj.create_date > 60 AND CURRENT_DATE - hasilbj.create_date <= 270 THEN 'Medium'
            WHEN CURRENT_DATE - hasilbj.create_date > 270 AND CURRENT_DATE - hasilbj.create_date <= 360 THEN 'Slow'
            ELSE 'Dead Stock'
             END)                           AS pallet_age_category,
         t3.is_rimpil                       AS is_rimpil
  FROM tbl_sp_hasilbj hasilbj
         LEFT JOIN inv_opname io ON hasilbj.pallet_no = io.io_no_pallet
         LEFT JOIN inv_master_lok_pallet iml ON io.io_kd_lok = iml.iml_kd_lok
         LEFT JOIN inv_master_area ima ON iml.iml_plan_kode = ima.plan_kode AND iml.iml_kd_area = ima.kd_area
         INNER JOIN (
                    -- find rimpils, based on stock with location
                    SELECT production_subplant,
                           motif_id,
                           motif_dimension,
                           motif_name,
                           quality,
                           size,
                           shading,
                           (CASE
                              WHEN current_quantity <= 2 * single_pallet_quantity THEN TRUE
                              ELSE FALSE END) AS is_rimpil
                    FROM (SELECT hasilbj.subplant       AS production_subplant,
                                 category.category_nama AS motif_dimension,
                                 item.item_kode         AS motif_id,
                                 item.item_nama         AS motif_name,
                                 item.quality           AS quality,
                                 hasilbj.size           AS size,
                                 hasilbj.shade          AS shading,
                                 category.jumlah_m2     AS single_pallet_quantity,
                                 SUM(last_qty)          AS current_quantity
                          FROM tbl_sp_hasilbj hasilbj
                                 LEFT JOIN inv_opname io ON hasilbj.pallet_no = io.io_no_pallet
                                 INNER JOIN item ON hasilbj.item_kode = item.item_kode
                                 INNER JOIN category ON SUBSTR(item.item_kode, 1, 2) = category.category_kode
                          WHERE hasilbj.last_qty > 0
                            AND io.io_kd_lok IS NOT NULL
                            AND hasilbj.status_plt = 'R'
                          GROUP BY production_subplant, motif_dimension, motif_id, item.quality, size, shading,
                                   single_pallet_quantity) t2) t3
           ON hasilbj.subplant = t3.production_subplant AND hasilbj.item_kode = t3.motif_id AND
              hasilbj.size = t3.size AND hasilbj.shade = t3.shading
  WHERE hasilbj.last_qty > 0
    AND hasilbj.status_plt = 'R'
    AND io.io_kd_lok IS NOT NULL;
