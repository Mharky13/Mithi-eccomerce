<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function productListingParam(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

$keyword = productListingParam('q');
$regionInput = productListingParam('region');
$category = productListingParam('category');
$sort = productListingParam('sort', 'newest');
$availability = productListingParam('availability', 'all');
$minPriceRaw = productListingParam('min_price');
$maxPriceRaw = productListingParam('max_price');
$pageRaw = productListingParam('page', '1');
$errors = [];

$allowedSorts = [
    'newest' => 'p.created_at DESC',
    'price_asc' => 'effective_price ASC, p.prod_name ASC',
    'price_desc' => 'effective_price DESC, p.prod_name ASC',
    'name_asc' => 'p.prod_name ASC',
    'name_desc' => 'p.prod_name DESC',
];
if (!isset($allowedSorts[$sort])) {
    $sort = 'newest';
}

$allowedAvailability = ['all', 'in_stock', 'out_of_stock', 'preorder'];
if (!in_array($availability, $allowedAvailability, true)) {
    $availability = 'all';
}

$regions = ['Bataan', 'Zambales'];
$region = in_array($regionInput, $regions, true) ? $regionInput : '';
if ($regionInput !== '' && $region === '') {
    $errors[] = 'Invalid region selected. The region filter was not applied.';
}

$minPrice = null;
$maxPrice = null;
$priceError = '';
if ($minPriceRaw !== '') {
    if (!is_numeric($minPriceRaw) || (float) $minPriceRaw < 0) {
        $priceError = 'Minimum price must be a non-negative number.';
    } else {
        $minPrice = (float) $minPriceRaw;
    }
}
if ($maxPriceRaw !== '') {
    if (!is_numeric($maxPriceRaw) || (float) $maxPriceRaw < 0) {
        $priceError = 'Maximum price must be a non-negative number.';
    } else {
        $maxPrice = (float) $maxPriceRaw;
    }
}
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    $priceError = 'Minimum price cannot be greater than maximum price.';
}
if ($priceError !== '') {
    $errors[] = $priceError . ' The price filter was not applied.';
    $minPrice = null;
    $maxPrice = null;
}

$page = ctype_digit($pageRaw) && (int) $pageRaw > 0 ? (int) $pageRaw : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;
$categories = [];
$products = [];
$totalProducts = 0;
$totalPages = 1;
$queryFailed = false;

try {
    $pdo = Database::connect();
} catch (Throwable $error) {
    error_log('Product listing database connection failed: ' . $error->getMessage());
    http_response_code(500);
    $errors[] = 'Products are temporarily unavailable because the database could not be reached.';
    $queryFailed = true;
}

if (!$queryFailed) {
    try {
        $categoryResult = $pdo->query(
            'SELECT categ_ID, categ_name FROM PRODUCT_CATEGORIES ORDER BY categ_name'
        );
        $categories = $categoryResult->fetchAll();
    } catch (Throwable $error) {
        error_log('Product listing category query failed: ' . $error->getMessage());
        $errors[] = 'Categories could not be loaded. Please try again later.';
    }

    $validCategoryIds = array_map(
        static fn(array $row): string => (string) $row['categ_ID'],
        $categories
    );
    if ($category !== '' && !in_array($category, $validCategoryIds, true)) {
        $errors[] = 'Invalid category selected. The category filter was not applied.';
        $category = '';
    }

    $where = ["p.availability_type IN ('In Stock', 'Available for Pre-order', 'Out of Stock')"];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(p.prod_name LIKE :name OR p.prod_description LIKE :description OR p.brand LIKE :brand OR s.seller_StoreName LIKE :store OR c.categ_name LIKE :category_name)';
        $term = '%' . $keyword . '%';
        $params += [
            'name' => $term,
            'description' => $term,
            'brand' => $term,
            'store' => $term,
            'category_name' => $term,
        ];
    }
    if ($region !== '') {
        $where[] = 'i.region = :region';
        $params['region'] = $region;
    }
    if ($category !== '') {
        $where[] = 'p.categ_ID = :category_id';
        $params['category_id'] = (int) $category;
    }

    $effectivePriceSql = '(CASE
        WHEN p.discount_price IS NOT NULL
            AND p.discount_price > 0
            AND p.discount_price < p.prod_price
        THEN p.discount_price
        ELSE p.prod_price
    END)';
    if ($minPrice !== null) {
        $where[] = $effectivePriceSql . ' >= :min_price';
        $params['min_price'] = $minPrice;
    }
    if ($maxPrice !== null) {
        $where[] = $effectivePriceSql . ' <= :max_price';
        $params['max_price'] = $maxPrice;
    }

    if ($availability === 'in_stock') {
        $where[] = "p.availability_type = 'In Stock' AND COALESCE(i.inventory_QTY, 0) > 0";
    } elseif ($availability === 'out_of_stock') {
        $where[] = "(p.availability_type = 'Out of Stock' OR (p.availability_type = 'In Stock' AND COALESCE(i.inventory_QTY, 0) <= 0))";
    } elseif ($availability === 'preorder') {
        $where[] = "p.availability_type = 'Available for Pre-order'";
    }

    $fromSql = '
        FROM PRODUCTS p
        INNER JOIN PRODUCT_CATEGORIES c ON c.categ_ID = p.categ_ID
        INNER JOIN SELLERS s ON s.seller_ID = p.seller_ID
        LEFT JOIN INVENTORY i ON i.prod_ID = p.prod_ID';
    $whereSql = implode(' AND ', $where);

    try {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT p.prod_ID) AS total ' . $fromSql . ' WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $totalProducts = (int) ($countStmt->fetch()['total'] ?? 0);
        $totalPages = max(1, (int) ceil($totalProducts / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = "
            SELECT
                p.prod_ID,
                p.prod_name,
                p.prod_description,
                p.prod_price,
                p.discount_price,
                p.availability_type,
                p.created_at,
                c.categ_name,
                s.seller_StoreName,
                COALESCE(i.inventory_QTY, 0) AS inventory_QTY,
                i.region,
                i.province,
                i.city_municipality,
                {$effectivePriceSql} AS effective_price,
                (
                    SELECT pi.img_URL
                    FROM PRODUCT_IMAGES pi
                    WHERE pi.prod_ID = p.prod_ID
                    ORDER BY pi.img_ID ASC
                    LIMIT 1
                ) AS img_URL
            {$fromSql}
            WHERE {$whereSql}
            GROUP BY
                p.prod_ID, p.prod_name, p.prod_description, p.prod_price,
                p.discount_price, p.availability_type, p.created_at,
                c.categ_name, s.seller_StoreName, i.inventory_QTY,
                i.region, i.province, i.city_municipality
            ORDER BY {$allowedSorts[$sort]}
            LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll();
    } catch (Throwable $error) {
        error_log('Product listing query failed: ' . $error->getMessage());
        http_response_code(500);
        $errors[] = 'Products could not be loaded. Check the database schema and try again later.';
        $products = [];
        $totalProducts = 0;
        $totalPages = 1;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'success' => !$queryFailed,
    'errors' => $errors,
    'categories' => $categories,
    'data' => $products,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total_products' => $totalProducts,
        'total_pages' => $totalPages,
    ],
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
