<?php
function cart_ingredient_requirements(mysqli $conn, array $cart): array
{
    $requirements = [];
    $sizeStatement = mysqli_prepare($conn, 'SELECT ingredient_id, qty_used FROM size_ingredient_usage WHERE size_id = ?');
    $addonStatement = mysqli_prepare($conn, 'SELECT ingredient_id, qty_used FROM addon_ingredient_usage WHERE addon_id = ?');
    if (!$sizeStatement || !$addonStatement) {
        if ($sizeStatement) {
            mysqli_stmt_close($sizeStatement);
        }
        if ($addonStatement) {
            mysqli_stmt_close($addonStatement);
        }
        throw new RuntimeException('Unable to verify ingredient requirements.');
    }

    foreach ($cart as $item) {
        $sizeId = filter_var($item['size_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
        $addonIds = $item['addons'] ?? [];
        if (!$sizeId || !$quantity || $quantity < 1 || !is_array($addonIds)) {
            throw new RuntimeException('The cart contains an invalid item.');
        }

        mysqli_stmt_bind_param($sizeStatement, 'i', $sizeId);
        if (!mysqli_stmt_execute($sizeStatement)) {
            throw new RuntimeException('Unable to verify ingredient requirements.');
        }
        $result = mysqli_stmt_get_result($sizeStatement);
        while ($row = mysqli_fetch_assoc($result)) {
            $ingredientId = (int) $row['ingredient_id'];
            $requirements[$ingredientId] = ($requirements[$ingredientId] ?? 0) + (float) $row['qty_used'] * $quantity;
        }

        foreach (array_unique($addonIds) as $addonId) {
            $addonId = filter_var($addonId, FILTER_VALIDATE_INT);
            if (!$addonId) {
                throw new RuntimeException('The cart contains an invalid add-on.');
            }
            mysqli_stmt_bind_param($addonStatement, 'i', $addonId);
            if (!mysqli_stmt_execute($addonStatement)) {
                throw new RuntimeException('Unable to verify ingredient requirements.');
            }
            $result = mysqli_stmt_get_result($addonStatement);
            while ($row = mysqli_fetch_assoc($result)) {
                $ingredientId = (int) $row['ingredient_id'];
                $requirements[$ingredientId] = ($requirements[$ingredientId] ?? 0) + (float) $row['qty_used'] * $quantity;
            }
        }
    }

    mysqli_stmt_close($sizeStatement);
    mysqli_stmt_close($addonStatement);
    return $requirements;
}

function check_ingredient_stock(mysqli $conn, array $requirements, bool $lockRows = false): ?string
{
    $statement = mysqli_prepare($conn, 'SELECT name, unit, stock_qty FROM ingredient WHERE ingredient_id = ?' . ($lockRows ? ' FOR UPDATE' : ''));
    if (!$statement) {
        throw new RuntimeException('Unable to verify ingredient stock.');
    }

    foreach ($requirements as $ingredientId => $required) {
        mysqli_stmt_bind_param($statement, 'i', $ingredientId);
        if (!mysqli_stmt_execute($statement)) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('Unable to verify ingredient stock.');
        }
        $ingredient = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        if (!$ingredient || (float) $ingredient['stock_qty'] < $required) {
            $name = $ingredient['name'] ?? 'An ingredient';
            $unit = $ingredient['unit'] ?? '';
            mysqli_stmt_close($statement);
            return $name . ' does not have enough stock (' . number_format((float) ($ingredient['stock_qty'] ?? 0), 2) . ' ' . $unit . ' available).';
        }
    }

    mysqli_stmt_close($statement);
    return null;
}

function order_ingredient_requirements(mysqli $conn, int $orderId): array
{
    $statement = mysqli_prepare($conn, 'SELECT required_ingredients.ingredient_id,
        SUM(required_ingredients.quantity_used) AS quantity_used
        FROM (
            SELECT siu.ingredient_id, siu.qty_used * oi.quantity AS quantity_used
            FROM order_item oi
            INNER JOIN size_ingredient_usage siu ON siu.size_id = oi.size_id
            WHERE oi.order_id = ?
            UNION ALL
            SELECT aiu.ingredient_id, aiu.qty_used * oi.quantity AS quantity_used
            FROM order_item oi
            INNER JOIN order_item_addon oia ON oia.order_item_id = oi.order_item_id
            INNER JOIN addon_ingredient_usage aiu ON aiu.addon_id = oia.addon_id
            WHERE oi.order_id = ?
        ) AS required_ingredients
        GROUP BY required_ingredients.ingredient_id');
    if (!$statement) {
        throw new RuntimeException('Unable to calculate order ingredient usage.');
    }

    mysqli_stmt_bind_param($statement, 'ii', $orderId, $orderId);
    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('Unable to calculate order ingredient usage.');
    }

    $requirements = [];
    $result = mysqli_stmt_get_result($statement);
    while ($row = mysqli_fetch_assoc($result)) {
        $requirements[(int) $row['ingredient_id']] = (float) $row['quantity_used'];
    }
    mysqli_stmt_close($statement);
    return $requirements;
}

function shop_is_open(array $settings, ?int $timestamp = null): bool
{
    $opening = $settings['opening_time'] ?? '10:00:00';
    $closing = $settings['closing_time'] ?? '20:00:00';
    $now = date('H:i:s', $timestamp ?? time());

    if ($opening === $closing) {
        return false;
    }
    if ($opening < $closing) {
        return $now >= $opening && $now < $closing;
    }
    return $now >= $opening || $now < $closing;
}
