INSERT INTO roles (code, name, is_system_role) VALUES
('admin', 'Administrator', 1), ('operator', 'Operations operator', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), is_system_role=VALUES(is_system_role);

INSERT INTO permissions (code, name) VALUES
('signals.view','View signals'), ('signals.cancel','Cancel a signal'), ('signals.retry','Retry failed dispatch'),
('municipalities.manage','Manage municipalities'), ('municipality_contacts.manage','Manage municipality contacts'),
('categories.manage','Manage signal categories'), ('audit.view','View audit log'), ('settings.manage','Manage settings')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code='admin'
ON DUPLICATE KEY UPDATE role_id=VALUES(role_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('signals.view','signals.retry','municipality_contacts.manage','audit.view') WHERE r.code='operator'
ON DUPLICATE KEY UPDATE role_id=VALUES(role_id);

INSERT INTO signal_statuses (code, name, sort_order, is_terminal) VALUES
('PENDING_EMAIL_VALIDATION','Pending email validation',10,0),
('EMAIL_VALIDATED','Email validated',20,0),
('READY_FOR_DISPATCH','Ready for dispatch',30,0),
('DISPATCHING','Dispatching',40,0),
('SENT','Sent',50,1), ('FAILED','Failed',60,0), ('CANCELLED','Cancelled',70,1), ('EXPIRED','Expired',80,1)
ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order), is_terminal=VALUES(is_terminal), is_active=1;

INSERT INTO signal_categories (code, name, description, sort_order) VALUES
('uncategorized','Uncategorized','Default MVP category',10),
('infrastructure','Infrastructure','Road, pavement or public infrastructure issue',20),
('environment','Environment','Waste, pollution or environmental issue',30)
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sort_order=VALUES(sort_order), is_active=1, deleted_at=NULL;

INSERT INTO settings (setting_key, setting_value) VALUES
('signal.max_photo_bytes','10485760'), ('signal.max_photo_pixels','24000000'), ('signal.validation_expiry_hours','24'),
('signal.validation_resend_cooldown_seconds','60'), ('signal.validation_max_resends','3'),
('geography.active_dataset','SU_BG_NSI_LAU_2024_1@2024.1')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
