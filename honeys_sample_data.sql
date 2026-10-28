-- OPTIONAL sample products for testing. Run after honeys_inventory.sql.
USE honeys_inventory;
INSERT INTO products (product_name, category, quantity, unit, price, low_stock_level) VALUES
 ('Whole dressed chicken', 'Chicken',      40, 'pcs',  280.00, 10),
 ('Chicken breast',        'Chicken',      25, 'kg',   220.00,  8),
 ('Chicken wings',         'Chicken',       4, 'kg',   190.00,  8),
 ('Hotdog',                'Frozen food',  30, 'pack',  95.00,  6),
 ('Chicken nuggets',       'Frozen food',   0, 'pack', 150.00,  5),
 ('Pork longganisa',       'Frozen food',  18, 'pack', 120.00,  5);
