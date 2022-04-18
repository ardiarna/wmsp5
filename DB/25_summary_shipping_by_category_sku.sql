DROP MATERIALIZED VIEW IF EXISTS summary_shipping_by_category_sku;
CREATE MATERIALIZED VIEW summary_shipping_by_category_sku AS
  SELECT sub_plant   AS subplant,
         sj.tanggal  AS ship_date,
         motif_id,
         motif_dimension,
         motif_name,
         quality,
         ship_type,
         ship_category,
         (CASE
            WHEN COALESCE(sj_detail.itsize, '') = '' THEN '-'
            ELSE sj_detail.itsize
             END)    AS size,
         (CASE
            WHEN COALESCE(sj_detail.itshade, '') = '' THEN '-'
            ELSE sj_detail.itshade
             END)    AS shading,
         SUM(volume) AS total_quantity
  FROM tbl_surat_jalan sj
         INNER JOIN tbl_detail_surat_jalan sj_detail on sj.no_surat_jalan = sj_detail.no_surat_jalan
         INNER JOIN (SELECT no_surat_jalan_rekap   AS sj_no,
                            ship.no_ba             AS ship_no,
                            (CASE
                               WHEN LEFT(ship.no_ba, 3) = 'BAL' THEN 'Lokal'
                               WHEN LEFT(ship.no_ba, 3) = 'BAM' THEN 'Regular'
                               ELSE 'UNKNOWN'
                                END)               AS ship_type,
                            ship_detail.detail_cat AS ship_category,
                            item.item_kode         AS motif_id,
                            item.item_nama         AS motif_name,
                            cat.category_nama      AS motif_dimension,
                            item.quality           AS quality
                     FROM tbl_ba_muat ship
                            INNER JOIN tbl_ba_muat_detail ship_detail on ship.no_ba = ship_detail.no_ba
                            INNER JOIN item ON ship_detail.item_kode = item.item_kode
                            INNER JOIN category cat on SUBSTR(item.item_kode, 1, 2) = cat.category_kode
                     WHERE ship.no_surat_jalan_rekap IS NOT NULL
                       AND detail_cat IN ('RIMPIL', 'SALES')) t1 ON sj.no_surat_jalan = t1.sj_no
  GROUP BY subplant, ship_date, motif_id, motif_dimension, motif_name, quality, ship_type, ship_category, size, shading
  UNION ALL
  -- get all for non-sales (FOC, SAMPLE)
  SELECT ship.sub_plan          AS subplant,
         sj.tanggal             AS ship_date,
         motif_id,
         motif_dimension,
         motif_name,
         quality,
         ship_type,
         ship_category,
         size,
         shading,
         SUM(ABS(mutation.qty)) AS total_quantity
  FROM tbl_ba_muat ship
         INNER JOIN tbl_surat_jalan sj ON ship.no_surat_jalan_rekap = sj.no_surat_jalan
         INNER JOIN (SELECT no_surat_jalan_rekap   AS sj_no,
                            ship.no_ba             AS ship_no,
                            (CASE
                               WHEN LEFT(ship.no_ba, 3) = 'BAL' THEN 'Lokal'
                               WHEN LEFT(ship.no_ba, 3) = 'BAM' THEN 'Regular'
                               ELSE 'UNKNOWN'
                                END)               AS ship_type,
                            ship_detail.detail_cat AS ship_category,
                            ship_detail.item_kode  AS motif_id,
                            item.item_nama         AS motif_name,
                            cat.category_nama      AS motif_dimension,
                            item.quality           AS quality,
                            (CASE
                               WHEN COALESCE(itsize, '') = '' THEN '-'
                               ELSE itsize
                                END)               AS size,
                            (CASE
                               WHEN COALESCE(itshade, '') = '' THEN '-'
                               ELSE itshade
                                END)               AS shading
                     FROM tbl_ba_muat ship
                            INNER JOIN tbl_ba_muat_detail ship_detail on ship.no_ba = ship_detail.no_ba
                            INNER JOIN item ON ship_detail.item_kode = item.item_kode
                            INNER JOIN category cat on SUBSTR(item.item_kode, 1, 2) = cat.category_kode
                     WHERE ship.no_surat_jalan_rekap IS NOT NULL
                       AND detail_cat NOT IN ('RIMPIL', 'SALES')) t4 ON ship.no_ba = t4.ship_no
         INNER JOIN tbl_sp_mutasi_pallet mutation ON ship.no_ba = mutation.no_mutasi
  GROUP BY subplant, ship_date, motif_id, motif_dimension, motif_name, quality, ship_type, ship_category, size, shading
  ORDER BY subplant, ship_date DESC, ship_category, ship_type, motif_id;

SELECT sub_plant   AS subplant,
       sj.tanggal  AS ship_date,
       motif_id,
       motif_dimension,
       motif_name,
       quality,
       ship_type,
       ship_category,
       (CASE
          WHEN COALESCE(sj_detail.itsize, '') = '' THEN 'N/A'
          ELSE sj_detail.itsize
           END)    AS size,
       (CASE
          WHEN COALESCE(sj_detail.itshade, '') = '' THEN 'N/A'
          ELSE sj_detail.itshade
           END)    AS shading,
       SUM(volume) AS total_quantity
FROM tbl_surat_jalan sj
       INNER JOIN tbl_detail_surat_jalan sj_detail on sj.no_surat_jalan = sj_detail.no_surat_jalan
       INNER JOIN (SELECT no_surat_jalan_rekap   AS sj_no,
                          ship.no_ba             AS ship_no,
                          (CASE
                             WHEN LEFT(ship.no_ba, 3) = 'BAL' THEN 'Lokal'
                             WHEN LEFT(ship.no_ba, 3) = 'BAM' THEN 'Regular'
                             ELSE 'UNKNOWN'
                              END)               AS ship_type,
                          ship_detail.detail_cat AS ship_category,
                          item.item_kode         AS motif_id,
                          item.item_nama         AS motif_name,
                          cat.category_nama      AS motif_dimension,
                          item.quality           AS quality
                   FROM tbl_ba_muat ship
                          INNER JOIN tbl_ba_muat_detail ship_detail on ship.no_ba = ship_detail.no_ba
                          INNER JOIN item ON ship_detail.item_kode = item.item_kode
                          INNER JOIN category cat on SUBSTR(item.item_kode, 1, 2) = cat.category_kode
                   WHERE ship.no_surat_jalan_rekap IS NOT NULL
                     AND detail_cat IN ('RIMPIL', 'SALES')) t1 ON sj.no_surat_jalan = t1.sj_no
GROUP BY subplant, ship_date, motif_id, motif_dimension, motif_name, quality, ship_type, ship_category, size, shading
UNION ALL
-- get all for non-sales (FOC, SAMPLE)
SELECT ship.sub_plan          AS subplant,
       sj.tanggal             AS ship_date,
       motif_id,
       motif_dimension,
       motif_name,
       quality,
       ship_type,
       ship_category,
       size,
       shading,
       SUM(ABS(mutation.qty)) AS total_quantity
FROM tbl_ba_muat ship
       INNER JOIN tbl_surat_jalan sj ON ship.no_surat_jalan_rekap = sj.no_surat_jalan
       INNER JOIN (SELECT no_surat_jalan_rekap   AS sj_no,
                          ship.no_ba             AS ship_no,
                          (CASE
                             WHEN LEFT(ship.no_ba, 3) = 'BAL' THEN 'Lokal'
                             WHEN LEFT(ship.no_ba, 3) = 'BAM' THEN 'Regular'
                             ELSE 'UNKNOWN'
                              END)               AS ship_type,
                          ship_detail.detail_cat AS ship_category,
                          ship_detail.item_kode  AS motif_id,
                          item.item_nama         AS motif_name,
                          cat.category_nama      AS motif_dimension,
                          item.quality           AS quality,
                          (CASE
                             WHEN COALESCE(itsize, '') = '' THEN 'N/A'
                             ELSE itsize
                              END)               AS size,
                          (CASE
                             WHEN COALESCE(itshade, '') = '' THEN 'N/A'
                             ELSE itshade
                              END)               AS shading
                   FROM tbl_ba_muat ship
                          INNER JOIN tbl_ba_muat_detail ship_detail on ship.no_ba = ship_detail.no_ba
                          INNER JOIN item ON ship_detail.item_kode = item.item_kode
                          INNER JOIN category cat on SUBSTR(item.item_kode, 1, 2) = cat.category_kode
                   WHERE ship.no_surat_jalan_rekap IS NOT NULL
                     AND detail_cat NOT IN ('RIMPIL', 'SALES')) t4 ON ship.no_ba = t4.ship_no
       INNER JOIN tbl_sp_mutasi_pallet mutation ON ship.no_ba = mutation.no_mutasi
GROUP BY subplant, ship_date, motif_id, motif_dimension, motif_name, quality, ship_type, ship_category, size, shading;


select sub_plant, kat, item_kode, volume
from (select (select array(
                       select detail_cat
                       from tbl_ba_muat c
                              inner join tbl_ba_muat_detail d on c.no_ba = d.no_ba
                       where no_surat_jalan_rekap = b.no_surat_jalan
                         and item_kode = b.item_kode
                         and itsize = b.itsize
                         and itshade = b.itshade
                         and sub_plant = b.sub_plant
                         and detail_cat in ('RIMPIL', 'SALES'))) AS kat, *
      from tbl_surat_jalan a
             inner join tbl_detail_surat_jalan b on a.no_surat_jalan = b.no_surat_jalan
      where a.no_surat_jalan in (select no_surat_jalan_rekap from tbl_ba_muat)
        and tanggal > '2018-09-01') as g
