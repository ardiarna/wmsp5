CREATE OR REPLACE VIEW summary_motifs_available_for_sales AS
  SELECT
    pallets_with_location.subplant                                AS production_subplant,
    item.quality                                                  AS quality,
    item.item_kode                                                AS motif_id,
    item.item_nama                                                AS motif_name,
    (SELECT category_nama
     FROM category
     WHERE category.category_kode = substr(item.item_kode, 1, 2)) AS motif_dimension,
    COUNT(pallets_with_location.pallet_no)                        AS pallet_count,
    SUM(pallets_with_location.last_qty)                           AS current_quantity
  FROM pallets_with_location
    INNER JOIN item ON pallets_with_location.item_kode = item.item_kode
  WHERE block_status = 0 AND last_qty > 0
  GROUP BY production_subplant, item.quality, motif_id, motif_name, motif_dimension;

CREATE OR REPLACE VIEW summary_pallets_available_for_sales AS
  SELECT
    pallets_with_location.subplant                                AS production_subplant,
    location_id                                                   AS location_id,
    item.quality                                                  AS quality,
    item.item_kode                                                AS motif_id,
    item.item_nama                                                AS motif_name,
    (SELECT category_nama
     FROM category
     WHERE category.category_kode = substr(item.item_kode, 1, 2)) AS motif_dimension,
    pallets_with_location.size                                    AS size,
    pallets_with_location.shade                                   AS shading,
    COUNT(pallets_with_location.pallet_no)                        AS pallet_count,
    SUM(pallets_with_location.last_qty)                           AS current_quantity
  FROM pallets_with_location
    INNER JOIN item ON pallets_with_location.item_kode = item.item_kode
  WHERE block_status = 0 AND last_qty > 0
  GROUP BY production_subplant, location_id, item.quality, motif_id, motif_name, motif_dimension, size, shading;
