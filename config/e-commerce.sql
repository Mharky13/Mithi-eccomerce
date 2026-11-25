--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ MITHI DATABASE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE DATABASE IF NOT EXISTS mithi_db;
USE mithi_db;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ USERS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE USERS (
    user_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_Email VARCHAR(100) NOT NULL UNIQUE,
    user_Password VARCHAR(255) NOT NULL,
    user_Role VARCHAR(20) NOT NULL,
    user_Status VARCHAR(20) NOT NULL DEFAULT 'Active',
    user_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (user_Role IN ('Customer', 'Seller', 'Admin')),
    CHECK (user_Status IN ('Active', 'Inactive', 'Suspended'))
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ CUSTOMERS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE CUSTOMERS (
    customer_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_ID INT NOT NULL UNIQUE,
    customer_FN VARCHAR(50) NOT NULL,
    customer_LN VARCHAR(50) NOT NULL,
    customer_Phone VARCHAR(20) UNIQUE,
    customer_Address VARCHAR(255),
    customer_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    customer_RegDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_ID)
        REFERENCES USERS(user_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ADMINS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE ADMINS (
    admin_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_ID INT NOT NULL UNIQUE,
    admin_FN VARCHAR(50) NOT NULL,
    admin_LN VARCHAR(50) NOT NULL,
    admin_Status VARCHAR(20) NOT NULL DEFAULT 'Active',
    admin_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (admin_Status IN ('Active', 'Inactive', 'Suspended')),
    FOREIGN KEY (user_ID)
        REFERENCES USERS(user_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ SELLERS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE SELLERS (
    seller_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_ID INT NOT NULL UNIQUE,
    seller_Phone VARCHAR(20) NOT NULL UNIQUE,
    seller_Email VARCHAR(100) NOT NULL,
    seller_FN VARCHAR(50) NOT NULL,
    seller_LN VARCHAR(50) NOT NULL,
    seller_City VARCHAR(100) NOT NULL,
    seller_Province VARCHAR(100) NOT NULL,
    seller_Region VARCHAR(100) NOT NULL,
    seller_RegDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    seller_StoreName VARCHAR(100) NOT NULL UNIQUE,
    FOREIGN KEY (user_ID)
        REFERENCES USERS(user_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ SELLER APPLICATIONS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE SELLER_APPLICATIONS (
    application_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_ID INT NOT NULL UNIQUE,
    admin_ID INT NULL,
    store_Name VARCHAR(100) NOT NULL UNIQUE,
    store_Address VARCHAR(255) NOT NULL,
    application_Status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    application_Date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    application_ReviewedAt DATETIME NULL,
    CHECK (application_Status IN ('Pending', 'Approved', 'Rejected')),
    CHECK (
        (application_Status = 'Pending' AND application_ReviewedAt IS NULL)
        OR (application_Status IN ('Approved', 'Rejected') AND application_ReviewedAt IS NOT NULL)
    ),
    FOREIGN KEY (user_ID)
        REFERENCES USERS(user_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (admin_ID)
        REFERENCES ADMINS(admin_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ PRODUCT CATEGORIES TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE PRODUCT_CATEGORIES (
    categ_ID INT AUTO_INCREMENT PRIMARY KEY,
    categ_name VARCHAR(100) NOT NULL UNIQUE,
    categ_description TEXT
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ PROMOTIONS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--


CREATE TABLE PROMOTIONS (
    promotion_ID INT AUTO_INCREMENT PRIMARY KEY,
    seller_ID INT NOT NULL,
    promotion_Name VARCHAR(100) NOT NULL,
    promotion_Description VARCHAR(255),
    promotion_StartDate DATETIME NOT NULL,
    promotion_EndDate DATETIME NOT NULL,
    promotion_Status VARCHAR(20) NOT NULL DEFAULT 'Active',
    promotion_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    promotion_UpdatedAt DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_promotion_dates
        CHECK (promotion_EndDate > promotion_StartDate),

    CONSTRAINT chk_promotion_status
        CHECK (promotion_Status IN (
            'Active',
            'Inactive',
            'Expired'
        )),

    FOREIGN KEY (seller_ID)
        REFERENCES SELLERS(seller_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB;



--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ PRODUCTS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE PRODUCTS (
    prod_ID INT AUTO_INCREMENT PRIMARY KEY,
    prod_name VARCHAR(150) NOT NULL,
    prod_description TEXT,
    prod_price DECIMAL(10,2) NOT NULL,
    categ_ID INT NOT NULL,
    seller_ID INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, --handles new arrivals sort by newswest date
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted BOOLEAN DEFAULT FALSE,
    deleted_at TIMESTAMP NULL,
    discount_price DECIMAL (10,2) DEFAULT NULL, --for onsale filter
    brand VARCHAR(100),
    prod_type VARCHAR(100),
    prod_condition VARCHAR(50) DEFAULT 'New',
    promotion_type VARCHAR(50) DEFAULT 'None',
    is_featured INT, 
    average_rating DECIMAL (3,2), --handles 4 stars and up logic
    review_count INT DEFAULT NULL, --most reviewed
    sales_count INT DEFAULT NULL, --best sellers
    view_count INT DEFAULT NULL, -- popular
    CHECK (prod_price >= 0),
    CHECK (
        discount_price IS NULL
        OR discount_price <= prod_price),
    CHECK (prod_condition IN ('New', 'Used', 'Refurbished')),
    CHECK (promotion_type IN ('Regular', 'Flash Sale', 'None')),
    FOREIGN KEY (categ_ID)
        REFERENCES PRODUCT_CATEGORIES(categ_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    FOREIGN KEY (seller_ID)
        REFERENCES SELLERS(seller_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ PRODUCT IMAGES TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE PRODUCT_IMAGES (
    img_ID INT AUTO_INCREMENT PRIMARY KEY,
    prod_ID INT NOT NULL,
    img_URL VARCHAR(2048) NOT NULL,
    FOREIGN KEY (prod_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ INVENTORY TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE INVENTORY (
    inventory_ID INT AUTO_INCREMENT PRIMARY KEY,
    prod_ID INT NOT NULL,
    inventory_QTY INT NOT NULL DEFAULT 0,
    availability_Status VARCHAR(20) NOT NULL DEFAULT 'In Stock',
    low_Stock_Threshold INT NOT NULL DEFAULT 10,
    region VARCHAR(100) NOT NULL, --location filter
    province VARCHAR(100) NOT NULL,
    city_municipality VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (inventory_QTY >= 0),
    CHECK (availability_Status IN ('In Stock', 'Out of Stock', 'Pre-Order')),
    FOREIGN KEY (prod_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
    UNIQUE (prod_ID, region, province, city_municipality)
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ Product_Variants TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE PRODUCT_VARIANTS (
    variant_ID INT AUTO_INCREMENT PRIMARY KEY,
    inventory_ID INT NOT NULL,
    variant_Size VARCHAR(50),
    variant_Color VARCHAR(50),
    variant_Price DECIMAL(10,2) NOT NULL,
    variant_Stock INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    -- Variant price cannot be negative
    CONSTRAINT chk_variant_price
        CHECK (variant_Price >= 0),

    -- Stock quantity cannot be negative
    CONSTRAINT chk_variant_stock
        CHECK (variant_Stock >= 0),

    -- Size cannot be empty or spaces only
    CONSTRAINT chk_variant_size
        CHECK (
            variant_Size IS NULL
            OR TRIM(variant_Size) <> ''
        ),

    -- Color cannot be empty or spaces only
    CONSTRAINT chk_variant_color
        CHECK (
            variant_Color IS NULL
            OR TRIM(variant_Color) <> ''
        ),

    -- At least size or color must be provided
    CONSTRAINT chk_variant_option
        CHECK (
            variant_Size IS NOT NULL
            OR variant_Color IS NOT NULL
        ),

    -- Prevent duplicate size and color combinations
    CONSTRAINT uq_inventory_variant
        UNIQUE (inventory_ID, variant_Size, variant_Color),

    -- Connect variants to inventory
    CONSTRAINT fk_variant_inventory
        FOREIGN KEY (inventory_ID)
        REFERENCES INVENTORY(inventory_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT unique_variant
        UNIQUE (inventory_ID, size, color)

) ENGINE=InnoDB;

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ PRODUCT DISCOUNTS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE PRODUCT_DISCOUNTS (
    discount_ID INT AUTO_INCREMENT PRIMARY KEY,

    promotion_ID INT NOT NULL,
    product_ID INT NOT NULL,

    discount_Type VARCHAR(20) NOT NULL,
    discount_Value DECIMAL(10,2) NOT NULL,

    discount_StartDate DATETIME NOT NULL,
    discount_EndDate DATETIME NOT NULL,

    discount_Status VARCHAR(20) NOT NULL DEFAULT 'Active',
    discount_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Links this discount to its promotion
    CONSTRAINT fk_product_discounts_promotion
        FOREIGN KEY (promotion_ID)
        REFERENCES PROMOTIONS(promotion_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Links this discount to its product
    CONSTRAINT fk_product_discounts_product
        FOREIGN KEY (product_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Prevents duplicate product assignments
    CONSTRAINT uq_promotion_product
        UNIQUE (promotion_ID, product_ID),

    -- Discount type must be Percentage or Fixed
    CONSTRAINT chk_discount_type
        CHECK (discount_Type IN ('Percentage', 'Fixed')),

    -- Validates the discount value
    CONSTRAINT chk_discount_value
        CHECK (
            (discount_Type = 'Percentage'
                AND discount_Value > 0
                AND discount_Value <= 100)
            OR
            (discount_Type = 'Fixed'
                AND discount_Value > 0)
        ),

    -- End date must be after start date
    CONSTRAINT chk_discount_dates
        CHECK (discount_EndDate > discount_StartDate),

    -- Validates discount status
    CONSTRAINT chk_discount_status
        CHECK (discount_Status IN ('Active', 'Inactive', 'Expired'))

) ENGINE=InnoDB;



--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ CART TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE CART (
    cart_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (status IN ('ACTIVE', 'COMPLETED', 'ABANDONED')),
    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT unique_customer_cart 
        UNIQUE (customer_ID)
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ORDERS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE ORDERS (
    order_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL,
    seller_ID INT NOT NULL,
    parent_Order_ID INT NULL,
    order_Type VARCHAR(50) NOT NULL DEFAULT 'Standard',
    order_Date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    order_Status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    subtotal_Amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_Amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_Fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_Amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    CHECK (total_Amount >= 0),
    CHECK (order_Status IN ('Pending', 'Confirmed', 'Processing', 'Shipped', 'Delivered', 'Cancelled', 'Refunded')),
    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    FOREIGN KEY (seller_ID)
        REFERENCES SELLERS(seller_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_parent_order
        FOREIGN KEY (parent_Order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ CART ITEM TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE CART_ITEM (
    cart_item_ID INT AUTO_INCREMENT PRIMARY KEY,
    cart_ID INT NOT NULL,
    inventory_ID INT NOT NULL,
    product_ID INT NOT NULL,
    item_QTY INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (item_QTY > 0),
    CHECK (price >= 0),
    FOREIGN KEY (cart_ID)
        REFERENCES CART(cart_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (inventory_ID)
        REFERENCES INVENTORY(inventory_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    FOREIGN KEY (product_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    UNIQUE (cart_ID, inventory_ID)
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ CartItemOptions TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

 CREATE TABLE CART_ITEM_OPTIONS (
    optionID INT AUTO_INCREMENT PRIMARY KEY,
    cart_item_ID INT NOT NULL,
    OptionName VARCHAR(100) NOT NULL,
    OptionValue VARCHAR(255) NOT NULL,
    AdditionalPrice DECIMAL(10,2) DEFAULT 0.00,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_additional_price       -- Add-on prices cannot be negative
        CHECK (AdditionalPrice >= 0),

    CONSTRAINT chk_option_name_not_blank    -- Option name cannot be empty or only spaces
        CHECK (TRIM(OptionName) <> ''),

    CONSTRAINT chk_option_value_not_blank    -- Option value cannot be empty or only spaces
        CHECK (TRIM(OptionValue) <> ''),

    FOREIGN KEY (cart_item_ID)
        REFERENCES CART_ITEM(cart_item_ID)
        ON DELETE CASCADE
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ORDER ITEMS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE order_items (
    order_item_ID INT AUTO_INCREMENT PRIMARY KEY,
    order_ID INT NOT NULL,
    product_ID INT NOT NULL,
    product_Name VARCHAR(255) NOT NULL,
    variant_Information VARCHAR(255),
    quantity INT NOT NULL,
    unit_Price DECIMAL(10,2) NOT NULL,
    discount_Amount DECIMAL(10,2) DEFAULT 0.00,
    subtotal DECIMAL(10,2) NOT NULL,

    CHECK (quantity > 0),
    CHECK (unit_Price >= 0),
    CHECK (discount_Amount >= 0),
    CHECK (subtotal >= 0),

    FOREIGN KEY (order_ID)
        REFERENCES orders(order_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (product_ID)
        REFERENCES products(prod_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ SHIPPING TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE SHIPPING (
    shipping_ID INT AUTO_INCREMENT PRIMARY KEY,
    order_ID INT NOT NULL,
    customer_address_ID INT NOT NULL,

    recipient_Name VARCHAR(100) NOT NULL,
    recipient_phone VARCHAR(20) NOT NULL,

    shipping_Address VARCHAR(255) NOT NULL,
    shipping_City VARCHAR(100) NOT NULL,
    shipping_Province VARCHAR(100) NOT NULL,
    shipping_PostalCode VARCHAR(20),

    shipping_Courier VARCHAR(100),
    estimated_DeliveryDate DATE,
    shipping_TrackingNo VARCHAR(100) UNIQUE,

    shipping_Status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    shipping_ShippedAt DATETIME NULL,
    shipping_DeliveredAt DATETIME NULL,
    shipping_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CHECK (shipping_Status IN (
        'Pending',
        'Preparing',
        'Shipped',
        'In Transit',
        'Delivered',
        'Failed',
        'Returned',
        'Cancelled'
    )),

    CHECK (
        shipping_DeliveredAt IS NULL
        OR shipping_ShippedAt IS NOT NULL
    ),

    CHECK (
        shipping_DeliveredAt IS NULL
        OR shipping_DeliveredAt >= shipping_ShippedAt
    ),

    FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (customer_address_ID)
        REFERENCES CUSTOMER_ADDRESS(customer_address_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB;



--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ PAYMENTS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE PAYMENTS (
    payment_ID INT AUTO_INCREMENT PRIMARY KEY,
    order_ID INT NOT NULL,
    payment_Method VARCHAR(50) NOT NULL,
    payment_Provider VARCHAR(100),
    payment_Amount DECIMAL(10,2) NOT NULL,
    payment_Status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    transaction_No VARCHAR(100) UNIQUE,
    payment_Date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    failure_Reason VARCHAR(255),

    CHECK (payment_Amount > 0),

    CHECK (payment_Status IN (
        'Pending',
        'Processing',
        'Paid',
        'Failed',
        'Cancelled',
        'Refunded'
    )),

    FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ COMPLAINTS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE COMPLAINTS (
    complaint_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL,
    seller_ID INT NULL,
    admin_ID INT NULL,
    order_ID INT NULL,
    complaint_Subject VARCHAR(100) NOT NULL,
    complaint_Description TEXT NOT NULL,
    complaint_Status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    resolution_Notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,

    -- Allowed complaint statuses
    CONSTRAINT chk_complaint_status
        CHECK (complaint_Status IN (
            'Pending',
            'In Progress',
            'Resolved',
            'Rejected',
            'Closed'
        )),

    -- Resolution date cannot be earlier than creation date
    CONSTRAINT chk_complaint_dates
        CHECK (
            resolved_at IS NULL
            OR resolved_at >= created_at
        ),

    -- Foreign keys
    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (seller_ID)
        REFERENCES SELLERS(seller_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    FOREIGN KEY (admin_ID)
        REFERENCES ADMINS(admin_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB;



--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ COUPONS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE COUPONS (
    coupon_ID INT AUTO_INCREMENT PRIMARY KEY,
    promotion_ID INT NOT NULL,

    coupon_Code VARCHAR(50) NOT NULL UNIQUE,

    coupon_DiscountType VARCHAR(20) NOT NULL,
    coupon_DiscountValue DECIMAL(10,2) NOT NULL,

    coupon_MinOrderAmount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    coupon_UsageLimit INT NULL,

    coupon_StartDate DATETIME NOT NULL,
    coupon_EndDate DATETIME NOT NULL,

    coupon_Status VARCHAR(20) NOT NULL DEFAULT 'Active',
    coupon_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
-- Coupons are linked to promotions. Referenced promotions cannot be deleted.
    CONSTRAINT fk_coupons_promotion  
        FOREIGN KEY (promotion_ID)    
        REFERENCES PROMOTIONS(promotion_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

-- Discount must be either a percentage or a fixed amount
    CONSTRAINT chk_coupon_type
        CHECK (coupon_DiscountType IN ('Percentage', 'Fixed')),

-- Percentage discounts must be between 0 and 100fixed discounts must be above 0
    CONSTRAINT chk_coupon_value
        CHECK (
            (coupon_DiscountType = 'Percentage'
                AND coupon_DiscountValue > 0
                AND coupon_DiscountValue <= 100)
            OR
            (coupon_DiscountType = 'Fixed'
                AND coupon_DiscountValue > 0)
        ),

    CONSTRAINT chk_coupon_min_order
        CHECK (coupon_MinOrderAmount >= 0),

    CONSTRAINT chk_coupon_usage_limit
        CHECK (coupon_UsageLimit IS NULL OR coupon_UsageLimit > 0),

    CONSTRAINT chk_coupon_dates
        CHECK (coupon_EndDate > coupon_StartDate),

    CONSTRAINT chk_coupon_status
        CHECK (coupon_Status IN ('Active', 'Inactive', 'Expired'))
) ENGINE=InnoDB;




--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ REFUNDS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE REFUNDS (
    refund_ID INT AUTO_INCREMENT PRIMARY KEY,
    payment_ID INT NOT NULL,
    order_ID INT NOT NULL,
    customer_ID INT NOT NULL,
    admin_ID INT NULL,
    refund_TotalAmount DECIMAL(10,2) NOT NULL,
    refund_Reason VARCHAR(255) NOT NULL,
    refund_Status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    refund_RequestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    refund_ProcessedAt DATETIME NULL,

--Refund amount must be greater than zero
     CONSTRAINT chk_refund_amount
        CHECK (refund_TotalAmount > 0),

--Refund status can only be 'Pending', 'Approved', 'Rejected', 'Processing', or 'Completed'
    CONSTRAINT chk_refund_status
        CHECK (refund_Status IN ('Pending', 'Approved', 'Rejected', 'Processing', 'Completed')),

--Processing time must be after the requested time if it is set
     CONSTRAINT chk_refund_processed_after_requested
        CHECK (refund_ProcessedAt IS NULL OR refund_ProcessedAt >= refund_RequestedAt),
    
    FOREIGN KEY (payment_ID) 
        REFERENCES PAYMENTS(payment_ID)
        ON UPDATE CASCADE 
        ON DELETE RESTRICT,
    FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    FOREIGN KEY (admin_ID)
        REFERENCES ADMINS(admin_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ BANNER TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE BANNERS (
    banner_ID INT AUTO_INCREMENT PRIMARY KEY,
    banner_Title VARCHAR(255) NOT NULL,
    banner_Image VARCHAR(255) NOT NULL,
    banner_Description TEXT,
    banner_Link VARCHAR(255) NULL,
    banner_Status VARCHAR(20) NOT NULL DEFAULT 'Active',
    banner_Order INT NOT NULL DEFAULT 0,
    banner_StartDate DATETIME NULL,
    banner_EndDate DATETIME NULL,
    banner_CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

--If both dates are set, the end date must be after the start date
    CONSTRAINT chk_banner_dates
        CHECK (
            banner_StartDate IS NULL
            OR banner_EndDate IS NULL
            OR banner_EndDate > banner_StartDate
        ),
-- Banner status can only be 'Active' or 'Inactive'
    CONSTRAINT chk_banner_status
        CHECK (banner_Status IN ('Active', 'Inactive'))
) ENGINE=InnoDB;

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ Featured Product TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚

CREATE TABLE FEATURED_PRODUCTS (
    featuredProduct_ID INT AUTO_INCREMENT PRIMARY KEY,
    product_ID INT NOT NULL,
    featuredProduct_CreatedAt DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    -- A product can only be featured once
    CONSTRAINT uq_featured_product    
        UNIQUE (product_ID), 

    FOREIGN KEY (product_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ NOTIFICATION TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--


CREATE TABLE NOTIFICATIONS (
    notification_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_ID INT NOT NULL,
    order_ID INT NULL,
    prod_ID INT NULL,

    notification_Type VARCHAR(50) NOT NULL DEFAULT 'General',
    notification_Title VARCHAR(255) NOT NULL,
    notification_Message TEXT NOT NULL,
    action_Link VARCHAR(255) NULL,

    notification_CreatedAt DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    notification_Status VARCHAR(20) NOT NULL DEFAULT 'Unread',
    notification_ReadAt DATETIME NULL,

    -- Allowed notification types
    CONSTRAINT chk_notification_type
        CHECK (notification_Type IN (
            'Order',
            'Payment',
            'Product',
            'Seller',
            'Promotion',
            'Account',
            'General'
        )),

    -- Notification status must be Unread or Read
    CONSTRAINT chk_notification_status
        CHECK (notification_Status IN ('Unread', 'Read')),

    -- Read date must match the notification status
    CONSTRAINT chk_notification_read
        CHECK (
            (notification_Status = 'Unread'
                AND notification_ReadAt IS NULL)
            OR
            (notification_Status = 'Read'
                AND notification_ReadAt IS NOT NULL)
        ),

    -- Foreign key for Users
    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_ID)
        REFERENCES USERS(user_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    -- Foreign key for Orders
    CONSTRAINT fk_notification_order
        FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    -- Foreign key for Products
    CONSTRAINT fk_notification_product
        FOREIGN KEY (prod_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ COUPON_REDEMPTION TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE coupon_redemption (
    redemption_ID INT AUTO_INCREMENT PRIMARY KEY,
    coupon_ID INT NOT NULL,
    customer_ID INT NOT NULL,
    order_ID INT NOT NULL UNIQUE,

    redeemed_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    discount_Amount DECIMAL(10,2) NOT NULL,

    -- Discount amount must be greater than zero
    CONSTRAINT chk_redemption_discount
        CHECK (discount_Amount > 0),

    -- Connects redemption to its coupon
    CONSTRAINT fk_redemption_coupon
        FOREIGN KEY (coupon_ID)
        REFERENCES COUPONS(coupon_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Connects redemption to its customer
    CONSTRAINT fk_redemption_customer
        FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Connects redemption to its order
    CONSTRAINT fk_redemption_order
        FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ REVIEWS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--
CREATE TABLE REVIEWS (
    review_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL,
    prod_ID INT NOT NULL,
    rating INT NOT NULL,
    comment TEXT,
    review_Date DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (prod_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CHECK (rating BETWEEN 1 AND 5)
);

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ WISHLIST TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--
CREATE TABLE WISHLIST (
    wishlist_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL,
    prod_ID INT NOT NULL,
    date_Added DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (prod_ID)
        REFERENCES PRODUCTS(prod_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    UNIQUE (customer_ID, prod_ID)
);

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ACTIVITY LOGS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--
CREATE TABLE ACTIVITY_LOGS (
    activity_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_ID INT NOT NULL,
    activity_Type VARCHAR(50) NOT NULL,
    activity_Description TEXT,
    activity_Date DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_ID)
        REFERENCES USERS(user_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ CUSTOMER ADDRESSES TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚
CREATE TABLE CUSTOMER_ADDRESSES (
    address_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL,
    address_Label VARCHAR(50) DEFAULT 'Home',
    recipient_Name VARCHAR(100) NOT NULL,
    street_Address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    postal_Code VARCHAR(20),

    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ADMIN ACTIVITY LOGS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚

CREATE TABLE ADMIN_ACTIVITY_LOGS (
    log_ID INT AUTO_INCREMENT PRIMARY KEY,
    admin_ID INT NOT NULL,
    action_Type VARCHAR(50) NOT NULL,
    action_Description TEXT,
    action_Date DATETIME DEFAULT CURRENT_TIMESTAMP,\
    target_Table VARCHAR(50) DEFAULT NULL,
    target_ID INT DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,

    FOREIGN KEY (admin_ID)
        REFERENCES ADMINS(admin_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ CUSTOMER PROFILE TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚

CREATE TABLE CUSTOMER_PROFILE (
    profile_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL,
    profile_picture VARCHAR(255),
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    gender VARCHAR(20),
    birth_date DATE,
    phone_number VARCHAR(20),
    address VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    -- First name cannot be empty or spaces only
    CONSTRAINT chk_firstname
        CHECK (TRIM(first_name) <> ''),

    -- Last name cannot be empty or spaces only
    CONSTRAINT chk_lastname
        CHECK (TRIM(last_name) <> ''),

    -- Gender must be one of the allowed values
    CONSTRAINT chk_gender
        CHECK (
            gender IN (
                'Male',
                'Female',
                'Other',
                'Prefer not to say'
            )
        ),

    -- Birth date cannot be earlier than 1900
    CONSTRAINT chk_birthdate
        CHECK (
            birth_date IS NULL
            OR birth_date >= '1900-01-01'
        ),

    -- Phone number must contain 10 to 20 characters
    CONSTRAINT chk_phone
        CHECK (
            phone_number IS NULL
            OR CHAR_LENGTH(TRIM(phone_number))
                BETWEEN 10 AND 20
        ),

    -- Foreign key
    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB;

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ACCOUNT SETTING TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE ACCOUNT_SETTING (
    account_setting_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_ID INT NOT NULL UNIQUE,
    email_notifications BOOLEAN DEFAULT TRUE,
    sms_notifications BOOLEAN DEFAULT FALSE,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    language VARCHAR(30) DEFAULT 'English',
    theme VARCHAR(20) DEFAULT 'Light',

    -- Email notifications must be TRUE or FALSE
    CONSTRAINT chk_email_notifications
        CHECK (email_notifications IN (0, 1)),

    -- SMS notifications must be TRUE or FALSE
    CONSTRAINT chk_sms_notifications
        CHECK (sms_notifications IN (0, 1)),

    -- Two-factor authentication must be TRUE or FALSE
    CONSTRAINT chk_two_factor
        CHECK (two_factor_enabled IN (0, 1)),

    -- Only allowed languages
    CONSTRAINT chk_language
        CHECK (language IN ('English', 'Filipino')),

    -- Only allowed themes
    CONSTRAINT chk_theme
        CHECK (theme IN ('Light', 'Dark', 'System')),

    -- Foreign Key
    FOREIGN KEY (customer_ID)
        REFERENCES CUSTOMERS(customer_ID)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB;


--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ORDER STATUS TABLE ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE ORDER_STATUS_HISTORY (
    status_history_ID INT AUTO_INCREMENT PRIMARY KEY,
    order_ID INT NOT NULL,
    shipping_ID INT NULL,
    status_value VARCHAR(50) NOT NULL,
    changed_by VARCHAR(100) NULL,
    notes TEXT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Only allowed order statuses
    CONSTRAINT chk_status_history
        CHECK (status_value IN (
            'Pending',
            'Confirmed',
            'Processing',
            'Shipped',
            'Delivered',
            'Cancelled',
            'Refunded'
        )),

    -- Changed by cannot be empty or spaces only
    CONSTRAINT chk_changed_by
        CHECK (
            changed_by IS NULL
            OR TRIM(changed_by) <> ''
        ),

    -- Notes cannot be empty or spaces only
    CONSTRAINT chk_history_notes
        CHECK (
            notes IS NULL
            OR TRIM(notes) <> ''
        ),

    -- Foreign key for Orders
    CONSTRAINT fk_status_history_order
        FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    -- Foreign key for Shipping
    CONSTRAINT fk_status_history_shipping
        FOREIGN KEY (shipping_ID)
        REFERENCES SHIPPING(shipping_ID)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB;

--˚₊‧꒰ა ☆ ໒꒱ ‧₊˚ ORDER STATUS HISTORY ˚₊‧꒰ა ☆ ໒꒱ ‧₊˚--

CREATE TABLE ORDER_STATUS_HISTORY (
    order_status_history_ID INT AUTO_INCREMENT PRIMARY KEY,
    order_ID INT NOT NULL,
    previous_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (order_ID)
        REFERENCES ORDERS(order_ID)
        ON UPDATE CASCADE
        ON DELETE CASCADE

) ENGINE=InnoDB;