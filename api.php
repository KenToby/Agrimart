<?php
// AgriMart JSON API.  Usage: api.php?action=<name>  (POST JSON body for writes)
session_start();
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function out($data, int $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400) { out(['ok' => false, 'error' => $msg], $code); }
function need_admin() { if (empty($_SESSION['admin'])) fail('Admin login required.', 401); }

const CATEGORIES = ['Vegetables','Fruits','Rice & Grains','Fish & Seafood','Poultry & Eggs'];
const UNITS      = ['kilo','piece','dozen','sack','bundle'];
const WTAGS      = ['Sunny/Dry Season','Rainy Season','All-Weather'];
const ICONS      = ['Vegetables'=>'🥬','Fruits'=>'🥭','Rice & Grains'=>'🌾','Fish & Seafood'=>'🐟','Poultry & Eggs'=>'🥚'];

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$pdo = db();

try {
switch ($action) {

/* ---------- Load everything the front-end needs ---------- */
case 'bootstrap':
    $farmers = $pdo->query('SELECT id,name,contact,description AS `desc`,member_id AS memberId,status FROM farmers ORDER BY created_at')->fetchAll();
    $isAdmin = !empty($_SESSION['admin']);
    $products = $pdo->query(
        'SELECT id,farmer_id AS farmerId,name,category,price,qty,unit,weather_tag AS weatherTag,
                harvest_date AS harvestDate,icon,photo FROM products ORDER BY created_at'
    )->fetchAll();
    foreach ($products as &$p) { $p['price'] = (float)$p['price']; $p['qty'] = (int)$p['qty']; } unset($p);

    $orders = $pdo->query(
        'SELECT id,buyer_name AS buyer,farmer_id AS farmerId,total,status,mode,
                pickup_date AS pickupDate,TIME_FORMAT(pickup_time,"%H:%i") AS pickupTime,
                phone,address,pay_method AS pay,farmer_note AS farmerNote,created_at AS createdAt
         FROM orders ORDER BY created_at, id'
    )->fetchAll();
    $itemsByOrder = [];
    foreach ($pdo->query('SELECT order_id,product_id AS productId,name,qty,price_each AS priceEach FROM order_items ORDER BY id') as $it) {
        $oid = $it['order_id']; unset($it['order_id']);
        $it['qty'] = (int)$it['qty']; $it['priceEach'] = (float)$it['priceEach'];
        $itemsByOrder[$oid][] = $it;
    }
    foreach ($orders as &$o) { $o['total'] = (float)$o['total']; $o['items'] = $itemsByOrder[$o['id']] ?? []; } unset($o);

    $usage = [];
    foreach ($pdo->query('SELECT member_id, farmer_id FROM member_ids ORDER BY member_id') as $r) $usage[$r['member_id']] = $r['farmer_id'];

    out(['ok'=>true, 'farmers'=>$farmers, 'products'=>$products, 'orders'=>$orders,
         'memberIdUsage'=>$usage, 'isAdmin'=>$isAdmin]);

/* ---------- Admin session ---------- */
case 'admin_login':
    $st = $pdo->prepare('SELECT password_hash FROM admins WHERE username = ?');
    $st->execute([trim($in['username'] ?? '')]);
    $h = $st->fetchColumn();
    if (!$h || !password_verify($in['password'] ?? '', $h)) fail('Incorrect username or password.', 401);
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    out(['ok'=>true]);

case 'admin_logout':
    $_SESSION = []; session_destroy();
    out(['ok'=>true]);

/* ---------- Farmer registration ---------- */
case 'farmer_register':
    $mid = strtoupper(trim($in['memberId'] ?? ''));
    $name = trim($in['name'] ?? ''); $contact = trim($in['contact'] ?? '');
    if ($mid === '' || $name === '' || $contact === '') fail('Please complete the Member ID, name, and phone number.');
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT farmer_id FROM member_ids WHERE member_id = ? FOR UPDATE');
    $st->execute([$mid]);
    $row = $st->fetch();
    if (!$row) { $pdo->rollBack(); fail('Invalid Member ID. Only registered Anonang-Barawas members with a valid Member ID may register as a seller.'); }
    if ($row['farmer_id']) { $pdo->rollBack(); fail('This Member ID has already been used to register an account.'); }
    $id = next_uid('f');
    $pdo->prepare('INSERT INTO farmers (id,name,contact,description,member_id,status) VALUES (?,?,?,?,?,"pending")')
        ->execute([$id, $name, $contact, trim($in['desc'] ?? ''), $mid]);
    $pdo->prepare('UPDATE member_ids SET farmer_id = ? WHERE member_id = ?')->execute([$id, $mid]);
    $pdo->commit();
    out(['ok'=>true, 'farmerId'=>$id]);

case 'farmer_reapply':
    $pdo->prepare('UPDATE farmers SET status="pending" WHERE id=? AND status="rejected"')->execute([$in['farmerId'] ?? '']);
    $st = $pdo->prepare('SELECT member_id FROM farmers WHERE id=?'); $st->execute([$in['farmerId'] ?? '']);
    if ($m = $st->fetchColumn()) $pdo->prepare('UPDATE member_ids SET farmer_id=? WHERE member_id=? AND farmer_id IS NULL')->execute([$in['farmerId'], $m]);
    out(['ok'=>true]);

/* ---------- Admin: approve / reject farmer ---------- */
case 'farmer_decide':
    need_admin();
    $decision = $in['decision'] ?? '';
    if (!in_array($decision, ['approved','rejected'], true)) fail('Bad decision.');
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE farmers SET status=? WHERE id=?')->execute([$decision, $in['farmerId'] ?? '']);
    if ($decision === 'rejected') // free the Member ID so the person can re-apply
        $pdo->prepare('UPDATE member_ids SET farmer_id=NULL WHERE farmer_id=?')->execute([$in['farmerId']]);
    $pdo->commit();
    out(['ok'=>true]);

/* ---------- Products ---------- */
case 'product_save':
    $fid = $in['farmerId'] ?? '';
    $st = $pdo->prepare('SELECT id FROM farmers WHERE id=? AND status="approved"'); $st->execute([$fid]);
    if (!$st->fetchColumn()) fail('Only approved farmers can list produce.', 403);

    $name = trim($in['name'] ?? ''); $cat = $in['category'] ?? ''; $unit = $in['unit'] ?? ''; $tag = $in['weatherTag'] ?? '';
    $price = (float)($in['price'] ?? 0); $qty = (int)($in['qty'] ?? -1); $harvest = $in['harvestDate'] ?? '';
    if ($name === '' || $price <= 0 || $qty < 0 || $harvest === '' ||
        !in_array($cat, CATEGORIES, true) || !in_array($unit, UNITS, true) || !in_array($tag, WTAGS, true))
        fail('Please complete all fields correctly.');
    $photo = $in['photo'] ?? null;
    if ($photo !== null && strpos($photo, 'data:image/') !== 0) $photo = null;
    if ($photo !== null && strlen($photo) > 4000000) fail('Photo is too large.');

    if (!empty($in['id'])) {
        $pdo->prepare('UPDATE products SET name=?,category=?,price=?,qty=?,unit=?,weather_tag=?,harvest_date=?,icon=?,photo=? WHERE id=? AND farmer_id=?')
            ->execute([$name,$cat,$price,$qty,$unit,$tag,$harvest,ICONS[$cat],$photo,$in['id'],$fid]);
        out(['ok'=>true, 'id'=>$in['id']]);
    }
    $id = next_uid('p');
    $pdo->prepare('INSERT INTO products (id,farmer_id,name,category,price,qty,unit,weather_tag,harvest_date,icon,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$fid,$name,$cat,$price,$qty,$unit,$tag,$harvest,ICONS[$cat],$photo]);
    out(['ok'=>true, 'id'=>$id]);

case 'product_delete':
    $pdo->prepare('DELETE FROM products WHERE id=? AND farmer_id=?')->execute([$in['id'] ?? '', $in['farmerId'] ?? '']);
    out(['ok'=>true]);

/* ---------- Orders ---------- */
case 'order_create':
    // $in: buyer, phone, mode, pickupDate, pickupTime, address, pay, items:[{productId,qty}]
    $buyer = trim($in['buyer'] ?? ''); $phone = trim($in['phone'] ?? '');
    $mode = ($in['mode'] ?? 'Pickup') === 'Delivery' ? 'Delivery' : 'Pickup';
    $items = $in['items'] ?? [];
    if ($buyer === '' || $phone === '' || !$items) fail('Missing order details.');
    if ($mode === 'Pickup' && (empty($in['pickupDate']) || empty($in['pickupTime']))) fail('Please choose a pickup date and time.');
    if ($mode === 'Delivery' && trim($in['address'] ?? '') === '') fail('Please enter a delivery address.');

    $pdo->beginTransaction();
    $byFarmer = [];
    foreach ($items as $it) {
        $q = (int)($it['qty'] ?? 0);
        if ($q <= 0) continue;
        $st = $pdo->prepare('SELECT p.*, f.status AS fstatus FROM products p JOIN farmers f ON f.id=p.farmer_id WHERE p.id=? FOR UPDATE');
        $st->execute([$it['productId'] ?? '']);
        $p = $st->fetch();
        if (!$p || $p['fstatus'] !== 'approved') { $pdo->rollBack(); fail('A product is no longer available.', 409); }
        if ($p['qty'] < $q) { $pdo->rollBack(); fail('Not enough stock for ' . $p['name'] . '.', 409); }
        $byFarmer[$p['farmer_id']][] = ['p'=>$p, 'q'=>$q];
    }
    if (!$byFarmer) { $pdo->rollBack(); fail('Basket is empty.'); }

    $ids = [];
    foreach ($byFarmer as $fid => $lines) {
        $oid = next_uid('SF'); $total = 0;
        foreach ($lines as $l) $total += $l['q'] * $l['p']['price'];
        $pdo->prepare('INSERT INTO orders (id,buyer_name,farmer_id,total,status,mode,pickup_date,pickup_time,phone,address,pay_method) VALUES (?,?,?,?,"Pending",?,?,?,?,?,?)')
            ->execute([$oid,$buyer,$fid,$total,$mode,
                       $mode==='Pickup' ? $in['pickupDate'] : null, $mode==='Pickup' ? $in['pickupTime'] : null,
                       $phone, $mode==='Delivery' ? trim($in['address']) : null, $in['pay'] ?? null]);
        foreach ($lines as $l) {
            $pdo->prepare('INSERT INTO order_items (order_id,product_id,name,qty,price_each) VALUES (?,?,?,?,?)')
                ->execute([$oid, $l['p']['id'], $l['p']['name'], $l['q'], $l['p']['price']]);
            $pdo->prepare('UPDATE products SET qty = qty - ? WHERE id=?')->execute([$l['q'], $l['p']['id']]);
        }
        $ids[] = $oid;
    }
    $pdo->commit();
    out(['ok'=>true, 'orderIds'=>$ids]);

case 'order_status':
    $oid = $in['orderId'] ?? ''; $new = $in['status'] ?? '';
    $flow = ['Approved'=>['Pending'], 'Rejected'=>['Pending'], 'Ready for Pickup'=>['Approved'], 'Completed'=>['Ready for Pickup']];
    if (!isset($flow[$new])) fail('Bad status.');
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT status FROM orders WHERE id=? FOR UPDATE'); $st->execute([$oid]);
    $cur = $st->fetchColumn();
    if (!$cur || !in_array($cur, $flow[$new], true)) { $pdo->rollBack(); fail('That order cannot be changed to ' . $new . ' right now.', 409); }
    if ($new === 'Rejected') { // return the stock
        $pdo->prepare('UPDATE products p JOIN order_items i ON i.product_id=p.id SET p.qty = p.qty + i.qty WHERE i.order_id=?')->execute([$oid]);
        $pdo->prepare('UPDATE orders SET status=?, farmer_note=? WHERE id=?')->execute([$new, trim($in['note'] ?? '') ?: 'Rejected by the farmer.', $oid]);
    } else {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$new, $oid]);
    }
    $pdo->commit();
    out(['ok'=>true]);

case 'order_adjust':
    // $in: orderId, quantities: { "<itemIndex>": newQty }  (index in order of items)
    $oid = $in['orderId'] ?? '';
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT status FROM orders WHERE id=? FOR UPDATE'); $st->execute([$oid]);
    if ($st->fetchColumn() !== 'Pending') { $pdo->rollBack(); fail('Only pending orders can be adjusted.', 409); }
    $st = $pdo->prepare('SELECT id,product_id,qty,price_each FROM order_items WHERE order_id=? ORDER BY id'); $st->execute([$oid]);
    $rows = $st->fetchAll(); $total = 0;
    foreach ($rows as $idx => $r) {
        $nq = (int)($in['quantities'][$idx] ?? $r['qty']);
        $nq = max(0, min($nq, (int)$r['qty']));            // can only lower, never raise
        if ($nq < $r['qty'] && $r['product_id'])
            $pdo->prepare('UPDATE products SET qty = qty + ? WHERE id=?')->execute([$r['qty'] - $nq, $r['product_id']]);
        $pdo->prepare('UPDATE order_items SET qty=? WHERE id=?')->execute([$nq, $r['id']]);
        $total += $nq * $r['price_each'];
    }
    $pdo->prepare('UPDATE orders SET total=?, status="Approved", farmer_note="Quantity adjusted based on available stock." WHERE id=?')->execute([$total, $oid]);
    $pdo->commit();
    out(['ok'=>true]);

default:
    fail('Unknown action.', 404);
}
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[agrimart] ' . $e->getMessage());
    fail('Server error. Check that MySQL is running and schema.sql was imported.', 500);
}
