-- Migration for Stripe columns
-- Add columns to usuarios table
ALTER TABLE usuarios ADD COLUMN stripe_public_key VARCHAR(255) DEFAULT NULL;
ALTER TABLE usuarios ADD COLUMN stripe_secret_key VARCHAR(255) DEFAULT NULL;
ALTER TABLE usuarios ADD COLUMN stripe_webhook_secret VARCHAR(255) DEFAULT NULL;

-- Add columns to saas_admin_gateways table if it exists
DO $$ 
BEGIN 
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'saas_admin_gateways') THEN 
        ALTER TABLE saas_admin_gateways ADD COLUMN stripe_public_key VARCHAR(255) DEFAULT NULL;
        ALTER TABLE saas_admin_gateways ADD COLUMN stripe_secret_key VARCHAR(255) DEFAULT NULL;
        ALTER TABLE saas_admin_gateways ADD COLUMN stripe_webhook_secret VARCHAR(255) DEFAULT NULL;
    END IF; 
END $$;
