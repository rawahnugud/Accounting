INSERT INTO company_profiles (company_id, address, contact, registration)
SELECT id, 'Port Sudan, Red Sea State, Sudan', '0129893789 · midnighttravel24@gmail.com · midnighttravel.net', ''
FROM companies
WHERE lower(trim(name)) IN (
    'midnight travel & tourism agency - sudan',
    'midnight travel & tourism agency – sudan'
)
ON CONFLICT(company_id) DO UPDATE SET
    address = CASE WHEN trim(company_profiles.address) = '' THEN excluded.address ELSE company_profiles.address END,
    contact = CASE WHEN trim(company_profiles.contact) = '' THEN excluded.contact ELSE company_profiles.contact END
WHERE trim(company_profiles.address) = '' OR trim(company_profiles.contact) = '';
