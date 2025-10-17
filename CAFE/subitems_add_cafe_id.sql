-- Add cafe_id column to subitems table after vendor_id
ALTER TABLE subitems ADD COLUMN cafe_id INT AFTER vendor_id;