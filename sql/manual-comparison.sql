SELECT
    COALESCE(l.COLUMN_NAME, a.COLUMN_NAME) AS column_name,
    -- Logistikus
    l.COLUMN_TYPE    AS logistikus_type,
    l.IS_NULLABLE    AS logistikus_nullable,
    l.COLUMN_DEFAULT AS logistikus_default,
    -- Asciiuat
    a.COLUMN_TYPE    AS brm_type,
    a.IS_NULLABLE    AS brm_nullable,
    a.COLUMN_DEFAULT AS brm_default,
    -- Difference flag
    CASE
        WHEN a.COLUMN_NAME IS NULL
          OR l.COLUMN_NAME IS NULL
          OR l.COLUMN_TYPE <> a.COLUMN_TYPE
          OR l.IS_NULLABLE <> a.IS_NULLABLE
          OR IFNULL(l.COLUMN_DEFAULT, '##') <> IFNULL(a.COLUMN_DEFAULT, '##')
        THEN 'YES'
        ELSE 'NO'
    END AS has_difference,
    -- Difference type
    CASE
        WHEN l.COLUMN_NAME IS NULL THEN 'only_in_brm'
        WHEN a.COLUMN_NAME IS NULL THEN 'only_in_logistikus'
        WHEN l.COLUMN_TYPE <> a.COLUMN_TYPE 
          AND l.IS_NULLABLE <> a.IS_NULLABLE 
          AND IFNULL(l.COLUMN_DEFAULT, '##') <> IFNULL(a.COLUMN_DEFAULT, '##') THEN 'multiple_differences'
        WHEN l.COLUMN_TYPE <> a.COLUMN_TYPE THEN 'type_mismatch'
        WHEN l.IS_NULLABLE <> a.IS_NULLABLE THEN 'nullable_mismatch'
        WHEN IFNULL(l.COLUMN_DEFAULT, '##') <> IFNULL(a.COLUMN_DEFAULT, '##') THEN 'default_mismatch'
        ELSE 'none'
    END AS difference_type
FROM INFORMATION_SCHEMA.COLUMNS l
LEFT JOIN INFORMATION_SCHEMA.COLUMNS a
    ON l.COLUMN_NAME = a.COLUMN_NAME
    AND a.TABLE_SCHEMA = 'brm_si'
    AND a.TABLE_NAME = 'si_cash_hdr'
WHERE l.TABLE_SCHEMA = 'logistikus_si'
  AND l.TABLE_NAME = 'si_cash_hdr'

UNION ALL

SELECT
    COALESCE(l.COLUMN_NAME, a.COLUMN_NAME) AS column_name,
    -- Logistikus
    l.COLUMN_TYPE    AS logistikus_type,
    l.IS_NULLABLE    AS logistikus_nullable,
    l.COLUMN_DEFAULT AS logistikus_default,
    -- Asciiuat
    a.COLUMN_TYPE    AS brm_type,
    a.IS_NULLABLE    AS brm_nullable,
    a.COLUMN_DEFAULT AS brm_default,
    -- Difference flag
    'YES' AS has_difference,
    'only_in_asciiuat' AS difference_type  -- column exists only in asciiuat
FROM INFORMATION_SCHEMA.COLUMNS a
LEFT JOIN INFORMATION_SCHEMA.COLUMNS l
    ON a.COLUMN_NAME = l.COLUMN_NAME
    AND l.TABLE_SCHEMA = 'logistikus_si'
    AND l.TABLE_NAME = 'si_cash_hdr'
WHERE a.TABLE_SCHEMA = 'brm_si'
  AND a.TABLE_NAME = 'si_cash_hdr'
  AND l.COLUMN_NAME IS NULL

ORDER BY column_name;