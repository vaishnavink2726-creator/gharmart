<?php

// ===============================
// CORS
// ===============================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");




// Handle browser preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


// Host:     sql303.infinityfree.com
// Port:     3306
// Username: if0_43034356
// Database: if0_43034356_XXX   ← this is a placeholder; choose the actual DB name
// Password: your MySQL password

// ===============================
// DATABASE CONNECTION
// ===============================
// $conn = new mysqli(
//     "localhost",
//     "root",
//     "",
//     "gharmart"
// );

$conn = new mysqli(
    "sql303.infinityfree.com",
    "if0_43034356",
    "Niri260524",
    "if0_43034356_gharmart",
    3306
);

if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}


// ===============================
// GET JSON DATA
// ===============================
$data = json_decode(
    file_get_contents("php://input"),
    true
);

$action = $data["action"] ?? "";

/* =========================================
   CUSTOMER SIGNUP
========================================= */

if ($action === "signup") {

    $name = trim($data["name"] ?? "");
    $mobile = trim($data["mobile"] ?? "");
    $email = trim($data["email"] ?? "");
    $password = $data["password"] ?? "";


    // Check required fields
    if (
        $name === "" ||
        $mobile === "" ||
        $email === "" ||
        $password === ""
    ) {
        echo json_encode([
            "success" => false,
            "message" => "All fields are required"
        ]);
        exit;
    }


    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid email address"
        ]);

        exit;
    }


    // Validate mobile number
    if (!preg_match('/^[0-9]{10}$/', $mobile)) {

        echo json_encode([
            "success" => false,
            "message" => "Mobile number must be 10 digits"
        ]);

        exit;
    }


    // Check duplicate email or mobile
    $check = $conn->prepare(
        "SELECT id
         FROM users
         WHERE email = ? OR mobile = ?"
    );

    $check->bind_param(
        "ss",
        $email,
        $mobile
    );

    $check->execute();

    $result = $check->get_result();


    if ($result->num_rows > 0) {

        echo json_encode([
            "success" => false,
            "message" => "Email or mobile already registered"
        ]);

        exit;
    }


    // Hash password
    $hashedPassword = password_hash(
        $password,
        PASSWORD_DEFAULT
    );


    // Insert new customer
    $stmt = $conn->prepare(
        "INSERT INTO users
        (name, mobile, email, password)
        VALUES (?, ?, ?, ?)"
    );

    $stmt->bind_param(
        "ssss",
        $name,
        $mobile,
        $email,
        $hashedPassword
    );


    if ($stmt->execute()) {

        echo json_encode([
            "success" => true,
            "message" => "Account created successfully",
            "user_id" => $stmt->insert_id
        ]);

    } else {

        echo json_encode([
            "success" => false,
            "message" => "Signup failed"
        ]);
    }

    exit;
}


/* =========================================
   CUSTOMER LOGIN
========================================= */

if ($action === "login") {

    $email = trim($data["email"] ?? "");
    $password = $data["password"] ?? "";


    // Check required fields
    if (
        $email === "" ||
        $password === ""
    ) {
        echo json_encode([
            "success" => false,
            "message" => "Email and password are required"
        ]);

        exit;
    }


    // Find customer by email
    $stmt = $conn->prepare(
        "SELECT
            id,
            name,
            mobile,
            email,
            password
         FROM users
         WHERE email = ?"
    );

    $stmt->bind_param(
        "s",
        $email
    );

    $stmt->execute();

    $result = $stmt->get_result();


    // User not found
    if ($result->num_rows === 0) {

        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);

        exit;
    }


    // Get user details
    $user = $result->fetch_assoc();


    // Verify password
    if (!password_verify(
        $password,
        $user["password"]
    )) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid password"
        ]);

        exit;
    }


    // Do not send password to React Native
    unset($user["password"]);


    // Login successful
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "user" => $user
    ]);

    exit;
}



/* =========================================
   GET NEARBY SHOPS
========================================= */

if ($action === "get_nearby_shops") {

    $latitude = $data["latitude"] ?? null;
    $longitude = $data["longitude"] ?? null;

    // Search radius in KM
    $radius = $data["radius"] ?? 10;


    // Check location
    if ($latitude === null || $longitude === null) {

        echo json_encode([
            "success" => false,
            "message" => "Location is required"
        ]);

        exit;
    }


    /*
       Calculate distance between customer
       and partner shop using Haversine formula.
    */

    $sql = "
        SELECT
            id,
            owner_name,
            shop_name,
            mobile,
            email,
            address,
            category,
            latitude,
            longitude,
            shop_status,
            opening_time,
            closing_time,

            (
                6371 * ACOS(
                    COS(RADIANS(?))
                    * COS(RADIANS(latitude))
                    * COS(
                        RADIANS(longitude)
                        - RADIANS(?)
                    )
                    + SIN(RADIANS(?))
                    * SIN(RADIANS(latitude))
                )
            ) AS distance

        FROM partners

        WHERE
            latitude IS NOT NULL
            AND longitude IS NOT NULL

        HAVING distance <= ?

        ORDER BY distance ASC
    ";


    $stmt = $conn->prepare($sql);


    if (!$stmt) {

        echo json_encode([
            "success" => false,
            "message" => "Query preparation failed"
        ]);

        exit;
    }


    $stmt->bind_param(
        "dddd",
        $latitude,
        $longitude,
        $latitude,
        $radius
    );


    $stmt->execute();

    $result = $stmt->get_result();


    $shops = [];


    while ($row = $result->fetch_assoc()) {

        $row["distance"] = round(
            (float)$row["distance"],
            2
        );

        $shops[] = $row;
    }


    echo json_encode([
        "success" => true,
        "shops" => $shops
    ]);

    exit;
}


/* =========================================
   GET PRODUCTS OF SELECTED SHOP
========================================= */

if ($action === "get_shop_products") {

    $partner_id = (int)($data["partner_id"] ?? 0);

    if ($partner_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid shop"
        ]);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            partner_id,
            product_name,
            description,
            category,
            price,
            stock_quantity,
            image_url,
            product_status,
            created_at,
            updated_at
         FROM products
         WHERE partner_id = ?
         ORDER BY id DESC"
    );

    $stmt->bind_param("i", $partner_id);

    $stmt->execute();

    $result = $stmt->get_result();

    $products = [];

    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }

    echo json_encode([
        "success" => true,
        "products" => $products
    ]);

    exit;
}


// ======================================================
// PLACE ORDER
// ======================================================

if ($action === 'place_order') {

    $partner_id = intval($data['partner_id'] ?? 0);

    $customer_name = trim(
        $data['customer_name'] ?? ''
    );

    $customer_mobile = trim(
        $data['customer_mobile'] ?? ''
    );

    $delivery_address = trim(
        $data['address'] ?? ''
    );

    $total_amount = floatval(
        $data['total_amount'] ?? 0
    );

    $payment_method = trim(
        $data['payment_method'] ?? ''
    );

    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;

    $products = $data['products'] ?? [];


    // ==================================================
    // VALIDATION
    // ==================================================

    if ($partner_id <= 0) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid shop."
        ]);

        exit;
    }


    if (empty($customer_name)) {

        echo json_encode([
            "success" => false,
            "message" => "Customer name is required."
        ]);

        exit;
    }


    if (empty($customer_mobile)) {

        echo json_encode([
            "success" => false,
            "message" => "Mobile number is required."
        ]);

        exit;
    }


    if (empty($delivery_address)) {

        echo json_encode([
            "success" => false,
            "message" => "Delivery address is required."
        ]);

        exit;
    }


    if ($total_amount <= 0) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid order amount."
        ]);

        exit;
    }


    if (!in_array(
        $payment_method,
        ['COD', 'UPI', 'CARD']
    )) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid payment method."
        ]);

        exit;
    }


    if (empty($products)) {

        echo json_encode([
            "success" => false,
            "message" => "Cart is empty."
        ]);

        exit;
    }


    // ==================================================
    // PAYMENT STATUS
    // ==================================================

    if ($payment_method === 'COD') {

        $payment_status = "Pending";

    } else {

        // UPI / CARD will remain pending
        // until real payment gateway is integrated

        $payment_status = "Pending";
    }


    $order_status = "Pending";


    // ==================================================
    // START TRANSACTION
    // ==================================================

    $conn->begin_transaction();


    try {

        // ==============================================
        // CHECK PRODUCTS
        // ==============================================

        foreach ($products as $product) {

            $product_id = intval(
                $product['product_id'] ?? 0
            );

            $quantity = intval(
                $product['quantity'] ?? 0
            );


            if ($product_id <= 0) {

                throw new Exception(
                    "Invalid product."
                );
            }


            if ($quantity <= 0) {

                throw new Exception(
                    "Invalid product quantity."
                );
            }


            // ------------------------------------------
            // GET PRODUCT
            // ------------------------------------------

            $stmt = $conn->prepare("
                SELECT
                    id,
                    partner_id,
                    product_name,
                    price,
                    stock_quantity
                FROM products
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->bind_param(
                "i",
                $product_id
            );

            $stmt->execute();

            $result = $stmt->get_result();


            if ($result->num_rows === 0) {

                throw new Exception(
                    "Product not found."
                );
            }


            $product_db =
                $result->fetch_assoc();


            // ------------------------------------------
            // CHECK SHOP
            // ------------------------------------------

            if (
                intval($product_db['partner_id'])
                !== $partner_id
            ) {

                throw new Exception(
                    $product_db['product_name']
                    . " does not belong to this shop."
                );
            }


            // ------------------------------------------
            // CHECK STOCK
            // ------------------------------------------

            $stock =
                intval(
                    $product_db['stock_quantity']
                );


            if ($stock <= 0) {

                throw new Exception(
                    $product_db['product_name']
                    . " is out of stock."
                );
            }


            if ($quantity > $stock) {

                throw new Exception(
                    "Only "
                    . $stock
                    . " "
                    . $product_db['product_name']
                    . " available."
                );
            }
        }


        // ==================================================
        // CREATE ORDER
        // ==================================================

        $stmt = $conn->prepare("
            INSERT INTO orders
            (
                partner_id,
                customer_name,
                customer_mobile,
                delivery_address,
                total_amount,
                order_status,
                payment_status,
                payment_method,
                latitude,
                longitude,
                created_at,
                updated_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW(),
                NOW()
            )
        ");


        $stmt->bind_param(
            "isssdsssss",
            $partner_id,
            $customer_name,
            $customer_mobile,
            $delivery_address,
            $total_amount,
            $order_status,
            $payment_status,
            $payment_method,
            $latitude,
            $longitude
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Unable to create order."
            );
        }


        // Get newly created order ID

        $order_id = $conn->insert_id;


        // ==================================================
        // INSERT ORDER ITEMS
        // ==================================================

        foreach ($products as $product) {

            $product_id =
                intval(
                    $product['product_id']
                );

            $quantity =
                intval(
                    $product['quantity']
                );


            // Get product information again

            $stmt = $conn->prepare("
                SELECT
                    product_name,
                    price
                FROM products
                WHERE id = ?
                FOR UPDATE
            ");


            $stmt->bind_param(
                "i",
                $product_id
            );


            $stmt->execute();

            $result =
                $stmt->get_result();


            $product_db =
                $result->fetch_assoc();


            $product_name =
                $product_db['product_name'];


            $price =
                floatval(
                    $product_db['price']
                );


            $item_total =
                $price * $quantity;


            // ------------------------------------------
            // INSERT ORDER ITEM
            // ------------------------------------------

          

            $stmt = $conn->prepare("
                INSERT INTO order_items
                (
                    order_id,
                    product_id,
                    product_name,
                    quantity,
                    price,
                    subtotal,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->bind_param(
                "iisidd",
                $order_id,
                $product_id,
                $product_name,
                $quantity,
                $price,
                $item_total
            );



            if (!$stmt->execute()) {

                throw new Exception(
                    "Unable to save order items."
                );
            }


            // ------------------------------------------
            // REDUCE STOCK
            // ------------------------------------------

            $stmt = $conn->prepare("
                UPDATE products
                SET stock_quantity =
                    stock_quantity - ?
                WHERE id = ?
                  AND stock_quantity >= ?
            ");


            $stmt->bind_param(
                "iii",
                $quantity,
                $product_id,
                $quantity
            );


            $stmt->execute();


            if ($stmt->affected_rows === 0) {

                throw new Exception(
                    "Stock changed. Please try again."
                );
            }
        }


        // ==================================================
        // SUCCESS
        // ==================================================

        $conn->commit();


        echo json_encode([
            "success" => true,
            "message" => "Order placed successfully.",
            "order_id" => $order_id,
            "order_status" => "Pending",
            "payment_status" => $payment_status
        ]);

        exit;


    } catch (Exception $e) {

        // ==================================================
        // ERROR
        // ==================================================

        $conn->rollback();


        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);

        exit;
    }
}


// ======================================================
// customer ORDER
// ======================================================
if ($action === 'get_customer_orders') {

    $customer_mobile = trim($data['customer_mobile'] ?? '');

    if ($customer_mobile === '') {

        echo json_encode([
            "success" => false,
            "message" => "Customer mobile number is required."
        ]);

        exit;
    }

    try {

        $stmt = $conn->prepare("
            SELECT
                o.id,
                o.partner_id,
                o.customer_name,
                o.customer_mobile,
                o.delivery_address,
                o.total_amount,
                o.order_status,
                o.payment_status,
                o.payment_method,
                o.latitude,
                o.longitude,
                o.created_at,
                o.updated_at,

                COUNT(oi.id) AS item_count,

                p.shop_name,
                p.address AS shop_address

            FROM orders o

            LEFT JOIN order_items oi
                ON o.id = oi.order_id

            LEFT JOIN partners p
                ON o.partner_id = p.id

            WHERE o.customer_mobile = ?

            GROUP BY
                o.id,
                o.partner_id,
                o.customer_name,
                o.customer_mobile,
                o.delivery_address,
                o.total_amount,
                o.order_status,
                o.payment_status,
                o.payment_method,
                o.latitude,
                o.longitude,
                o.created_at,
                o.updated_at,
                p.shop_name,
                p.address

            ORDER BY o.id DESC
        ");

        $stmt->bind_param(
            "s",
            $customer_mobile
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $orders = [];

        while ($row = $result->fetch_assoc()) {

            $orders[] = $row;
        }

        $stmt->close();

        echo json_encode([
            "success" => true,
            "orders" => $orders
        ]);

    } catch (Exception $e) {

        echo json_encode([
            "success" => false,
            "message" => "Unable to load orders.",
            "error" => $e->getMessage()
        ]);
    }

    exit;
}


// ===============================
// PARTNER SIGNUP
// ===============================
if ($action === "partner_signup") {

    $owner_name = trim($data["owner_name"] ?? "");
    $shop_name = trim($data["shop_name"] ?? "");
    $mobile = trim($data["mobile"] ?? "");
    $email = trim($data["email"] ?? "");
    $address = trim($data["address"] ?? "");
    $category = trim($data["category"] ?? "");
    $password = $data["password"] ?? "";

    $latitude = $data["latitude"] ?? null;
    $longitude = $data["longitude"] ?? null;


    // ===============================
    // VALIDATION
    // ===============================
    if (
        empty($owner_name) ||
        empty($shop_name) ||
        empty($mobile) ||
        empty($email) ||
        empty($address) ||
        empty($category) ||
        empty($password)
    ) {
        echo json_encode([
            "success" => false,
            "message" => "All fields are required"
        ]);
        exit;
    }


    // ===============================
    // CHECK EMAIL
    // ===============================
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid email address"
        ]);
        exit;
    }


    // ===============================
    // CHECK MOBILE
    // ===============================
    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        echo json_encode([
            "success" => false,
            "message" => "Mobile number must contain 10 digits"
        ]);
        exit;
    }


    // ===============================
    // CHECK EXISTING EMAIL
    // ===============================
    $checkEmail = $conn->prepare(
        "SELECT id FROM partners WHERE email = ?"
    );

    $checkEmail->bind_param(
        "s",
        $email
    );

    $checkEmail->execute();

    $resultEmail = $checkEmail->get_result();

    if ($resultEmail->num_rows > 0) {

        echo json_encode([
            "success" => false,
            "message" => "Email already registered"
        ]);

        $checkEmail->close();
        $conn->close();
        exit;
    }

    $checkEmail->close();


    // ===============================
    // CHECK EXISTING MOBILE
    // ===============================
    $checkMobile = $conn->prepare(
        "SELECT id FROM partners WHERE mobile = ?"
    );

    $checkMobile->bind_param(
        "s",
        $mobile
    );

    $checkMobile->execute();

    $resultMobile = $checkMobile->get_result();

    if ($resultMobile->num_rows > 0) {

        echo json_encode([
            "success" => false,
            "message" => "Mobile number already registered"
        ]);

        $checkMobile->close();
        $conn->close();
        exit;
    }

    $checkMobile->close();


    // ===============================
    // HASH PASSWORD
    // ===============================
    $hashedPassword = password_hash(
        $password,
        PASSWORD_DEFAULT
    );


    // ===============================
    // INSERT PARTNER
    // ===============================
    $sql = "INSERT INTO partners
            (
                owner_name,
                shop_name,
                mobile,
                email,
                address,
                category,
                password,
                latitude,
                longitude
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        echo json_encode([
            "success" => false,
            "message" => "SQL prepare failed: " . $conn->error
        ]);

        $conn->close();
        exit;
    }


    $stmt->bind_param(
        "sssssssdd",
        $owner_name,
        $shop_name,
        $mobile,
        $email,
        $address,
        $category,
        $hashedPassword,
        $latitude,
        $longitude
    );


    // ===============================
    // SAVE
    // ===============================
    if ($stmt->execute()) {

        echo json_encode([
            "success" => true,
            "message" => "Partner account created successfully",
            "partner_id" => $stmt->insert_id
        ]);

    } else {

        echo json_encode([
            "success" => false,
            "message" => "Signup failed: " . $stmt->error
        ]);
    }


    $stmt->close();
    $conn->close();
    exit;
}


// ===============================
// PARTNER LOGIN
// ===============================
if ($action === "partner_login") {

    $email = trim($data["email"] ?? "");
    $password = $data["password"] ?? "";

    if (empty($email) || empty($password)) {
        echo json_encode([
            "success" => false,
            "message" => "Email and password are required"
        ]);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            owner_name,
            shop_name,
            mobile,
            email,
            address,
            category,
            password,
            latitude,
            longitude,
            shop_status,
            opening_time,
            closing_time
         FROM partners
         WHERE email = ?"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        echo json_encode([
            "success" => false,
            "message" => "Partner account not found"
        ]);

        $stmt->close();
        $conn->close();
        exit;
    }

    $partner = $result->fetch_assoc();

    if (!password_verify($password, $partner["password"])) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid password"
        ]);

        $stmt->close();
        $conn->close();
        exit;
    }

    unset($partner["password"]);

    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "partner" => $partner
    ]);

    $stmt->close();
    $conn->close();
    exit;
}


// ===============================
// GET PARTNER PRODUCTS
// ===============================
if ($action === "get_partner_products") {

    $partner_id = intval($data["partner_id"] ?? 0);

    if ($partner_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid partner ID"
        ]);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            partner_id,
            product_name,
            description,
            category,
            price,
            stock_quantity,
            image_url,
            product_status,
            created_at,
            updated_at
         FROM products
         WHERE partner_id = ?
         ORDER BY id DESC"
    );

    $stmt->bind_param("i", $partner_id);

    $stmt->execute();

    $result = $stmt->get_result();

    $products = [];

    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }

    echo json_encode([
        "success" => true,
        "products" => $products
    ]);

    $stmt->close();
    $conn->close();
    exit;
}


// ===============================
// ADD PRODUCT
// ===============================
if ($action === "add_product") {

    $partner_id = intval($data["partner_id"] ?? 0);
    $product_name = trim($data["product_name"] ?? "");
    $description = trim($data["description"] ?? "");
    $category = trim($data["category"] ?? "");
    $price = floatval($data["price"] ?? 0);
    $stock_quantity = intval($data["stock_quantity"] ?? 0);
    $image_url = $data["image_url"] ?? null;
    $product_status = $data["product_status"] ?? "Available";

    if ($partner_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid partner ID"
        ]);
        exit;
    }

    if ($product_name === "") {
        echo json_encode([
            "success" => false,
            "message" => "Product name is required"
        ]);
        exit;
    }

    if ($category === "") {
        echo json_encode([
            "success" => false,
            "message" => "Category is required"
        ]);
        exit;
    }

    if ($price <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Price must be greater than 0"
        ]);
        exit;
    }

    if ($stock_quantity < 0) {
        echo json_encode([
            "success" => false,
            "message" => "Stock quantity cannot be negative"
        ]);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO products
        (
            partner_id,
            product_name,
            description,
            category,
            price,
            stock_quantity,
            image_url,
            product_status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        echo json_encode([
            "success" => false,
            "message" => "Prepare failed: " . $conn->error
        ]);
        exit;
    }

    // IMPORTANT: 8 variables = 8 type characters
    $stmt->bind_param(
        "isssdiss",
        $partner_id,
        $product_name,
        $description,
        $category,
        $price,
        $stock_quantity,
        $image_url,
        $product_status
    );

    if ($stmt->execute()) {

        echo json_encode([
            "success" => true,
            "message" => "Product added successfully",
            "product_id" => $stmt->insert_id
        ]);

    } else {

        echo json_encode([
            "success" => false,
            "message" => "Failed to add product: " . $stmt->error
        ]);
    }

    $stmt->close();
    $conn->close();
    exit;
}


if ($action === 'get_partner_orders') {

    $partner_id = intval($data['partner_id'] ?? 0);

    if ($partner_id <= 0) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid partner ID."
        ]);

        exit;
    }

    try {

        // Get all orders for this partner
        $orderStmt = $conn->prepare("
            SELECT
                id,
                partner_id,
                customer_name,
                customer_mobile,
                delivery_address,
                total_amount,
                order_status,
                payment_status,
                payment_method,
                latitude,
                longitude,
                created_at,
                updated_at
            FROM orders
            WHERE partner_id = ?
            ORDER BY id DESC
        ");

        $orderStmt->bind_param(
            "i",
            $partner_id
        );

        $orderStmt->execute();

        $orderResult = $orderStmt->get_result();

        $orders = [];

        while ($order = $orderResult->fetch_assoc()) {

            $order_id = intval($order['id']);

            // Get products for this order
            $itemStmt = $conn->prepare("
                SELECT
                    id,
                    order_id,
                    product_id,
                    product_name,
                    quantity,
                    price,
                    subtotal,
                    created_at
                FROM order_items
                WHERE order_id = ?
                ORDER BY id ASC
            ");

            $itemStmt->bind_param(
                "i",
                $order_id
            );

            $itemStmt->execute();

            $itemResult = $itemStmt->get_result();

            $products = [];

            while ($item = $itemResult->fetch_assoc()) {

                $products[] = [
                    "id" => intval($item['id']),
                    "order_id" => intval($item['order_id']),
                    "product_id" => intval($item['product_id']),
                    "product_name" => $item['product_name'],
                    "quantity" => intval($item['quantity']),
                    "price" => $item['price'],
                    "subtotal" => $item['subtotal'],
                    "created_at" => $item['created_at'],
                ];
            }

            $itemStmt->close();

            // Add products inside order
            $order['products'] = $products;

            $orders[] = $order;
        }

        $orderStmt->close();

        echo json_encode([
            "success" => true,
            "orders" => $orders
        ]);

    } catch (Exception $e) {

        echo json_encode([
            "success" => false,
            "message" => "Unable to load partner orders.",
            "error" => $e->getMessage()
        ]);
    }

    exit;
}


if ($action === 'update_order_status') {

    $order_id = intval($data['order_id'] ?? 0);
    $partner_id = intval($data['partner_id'] ?? 0);
    $order_status = trim($data['order_status'] ?? '');

    $allowedStatuses = [
        'Pending',
        'Accepted',
        'Rejected',
        'Preparing',
        'Ready',
        'Out for Delivery',
        'Completed',
        'Cancelled'
    ];

    if (
        $order_id <= 0 ||
        $partner_id <= 0 ||
        !in_array($order_status, $allowedStatuses, true)
    ) {

        echo json_encode([
            'success' => false,
            'message' => 'Invalid order information'
        ]);

        exit;
    }


    // Check order belongs to this partner

    $check = $conn->prepare("
        SELECT
            id,
            order_status,
            payment_status,
            payment_method
        FROM orders
        WHERE id = ?
        AND partner_id = ?
    ");

    $check->bind_param(
        "ii",
        $order_id,
        $partner_id
    );

    $check->execute();

    $result = $check->get_result();

    $order = $result->fetch_assoc();

    $check->close();


    if (!$order) {

        echo json_encode([
            'success' => false,
            'message' => 'Order not found'
        ]);

        exit;
    }


    /*
     * PAYMENT STATUS
     *
     * COD:
     * When order is completed,
     * payment becomes Paid.
     *
     * UPI/CARD:
     * Keep current payment status until
     * real payment gateway is integrated.
     */

    $payment_status = $order['payment_status'];

    if (
        $order_status === 'Completed' &&
        $order['payment_method'] === 'COD'
    ) {

        $payment_status = 'Paid';

    }


    // Update order

    $update = $conn->prepare("
        UPDATE orders
        SET
            order_status = ?,
            payment_status = ?,
            updated_at = NOW()
        WHERE id = ?
        AND partner_id = ?
    ");

    $update->bind_param(
        "ssii",
        $order_status,
        $payment_status,
        $order_id,
        $partner_id
    );


    if ($update->execute()) {

        echo json_encode([
            'success' => true,
            'message' => 'Order status updated successfully',
            'order_status' => $order_status,
            'payment_status' => $payment_status
        ]);

    } else {

        echo json_encode([
            'success' => false,
            'message' => 'Failed to update order status'
        ]);
    }


    $update->close();

    exit;
}



if ($action === "get_partner_profile") {

    $partner_id = intval($data["partner_id"] ?? 0);

    if ($partner_id <= 0) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid partner ID"
        ]);

        $conn->close();
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            owner_name,
            shop_name,
            mobile,
            email,
            address,
            category,
            latitude,
            longitude,
            shop_status,
            opening_time,
            closing_time
         FROM partners
         WHERE id = ?"
    );

    if (!$stmt) {

        echo json_encode([
            "success" => false,
            "message" => "Prepare failed: " . $conn->error
        ]);

        $conn->close();
        exit;
    }

    $stmt->bind_param("i", $partner_id);

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        echo json_encode([
            "success" => false,
            "message" => "Partner not found"
        ]);

        $stmt->close();
        $conn->close();
        exit;
    }

    $partner = $result->fetch_assoc();

    echo json_encode([
        "success" => true,
        "message" => "Partner profile loaded successfully",
        "partner" => $partner
    ]);

    $stmt->close();
    $conn->close();
    exit;
}


if ($action === "update_partner_profile") {

    $partner_id = intval($data["partner_id"] ?? 0);

    $owner_name = trim($data["owner_name"] ?? "");
    $shop_name = trim($data["shop_name"] ?? "");
    $mobile = trim($data["mobile"] ?? "");
    $email = trim($data["email"] ?? "");
    $address = trim($data["address"] ?? "");
    $category = trim($data["category"] ?? "");
    $shop_status = trim($data["shop_status"] ?? "");
    $opening_time = trim($data["opening_time"] ?? "");
    $closing_time = trim($data["closing_time"] ?? "");


    // =========================================
    // VALIDATE PARTNER ID
    // =========================================

    if ($partner_id <= 0) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid partner ID"
        ]);

        $conn->close();
        exit;
    }


    // =========================================
    // VALIDATE REQUIRED FIELDS
    // =========================================

    if (
        $owner_name === "" ||
        $shop_name === "" ||
        $mobile === "" ||
        $email === "" ||
        $address === "" ||
        $category === "" ||
        $opening_time === "" ||
        $closing_time === ""
    ) {

        echo json_encode([
            "success" => false,
            "message" => "All shop details are required"
        ]);

        $conn->close();
        exit;
    }


    // =========================================
    // VALIDATE MOBILE
    // =========================================

    if (!preg_match('/^[0-9]{10}$/', $mobile)) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid 10-digit mobile number"
        ]);

        $conn->close();
        exit;
    }


    // =========================================
    // VALIDATE EMAIL
    // =========================================

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid email address"
        ]);

        $conn->close();
        exit;
    }


    // =========================================
    // VALIDATE SHOP STATUS
    // =========================================

    if (
        $shop_status !== "Open" &&
        $shop_status !== "Closed"
    ) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid shop status"
        ]);

        $conn->close();
        exit;
    }


    // =========================================
    // UPDATE DATABASE
    // =========================================

    $stmt = $conn->prepare(
        "UPDATE partners
         SET
            owner_name = ?,
            shop_name = ?,
            mobile = ?,
            email = ?,
            address = ?,
            category = ?,
            shop_status = ?,
            opening_time = ?,
            closing_time = ?
         WHERE id = ?"
    );


    // =========================================
    // CHECK PREPARE
    // =========================================

    if (!$stmt) {

        echo json_encode([
            "success" => false,
            "message" => "Prepare failed: " . $conn->error
        ]);

        $conn->close();
        exit;
    }


    // =========================================
    // BIND PARAMETERS
    // =========================================

    $stmt->bind_param(
        "sssssssssi",
        $owner_name,
        $shop_name,
        $mobile,
        $email,
        $address,
        $category,
        $shop_status,
        $opening_time,
        $closing_time,
        $partner_id
    );


    // =========================================
    // EXECUTE UPDATE
    // =========================================

    if ($stmt->execute()) {

        echo json_encode([
            "success" => true,
            "message" => "Shop profile updated successfully"
        ]);

    } else {

        echo json_encode([
            "success" => false,
            "message" => "Failed to update shop profile: " . $stmt->error
        ]);
    }


    // =========================================
    // CLOSE
    // =========================================

    $stmt->close();
    $conn->close();
    exit;
}



// ===============================
// INVALID ACTION
// ===============================
echo json_encode([
    "success" => false,
    "message" => "Invalid action"
]);

?>
