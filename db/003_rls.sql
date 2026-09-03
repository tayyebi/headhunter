-- Row level security. The connection carries the real PostgreSQL role, so the
-- database decides visibility and no PHP query ever filters by owner.
--
-- FORCE is not used: the tables are owned by the superuser, and nothing logs in
-- as the superuser, so the owner-bypass is unreachable in practice.

ALTER TABLE candidates ENABLE ROW LEVEL SECURITY;
ALTER TABLE resumes    ENABLE ROW LEVEL SECURITY;
ALTER TABLE runs       ENABLE ROW LEVEL SECURITY;
ALTER TABLE deliveries ENABLE ROW LEVEL SECURITY;
ALTER TABLE settings   ENABLE ROW LEVEL SECURITY;

-- Headhunters share one candidate pool and see all of it.
CREATE POLICY admin_all ON candidates FOR ALL TO hh_admin USING (true) WITH CHECK (true);
CREATE POLICY admin_all ON resumes    FOR ALL TO hh_admin USING (true) WITH CHECK (true);
CREATE POLICY admin_all ON runs       FOR ALL TO hh_admin USING (true) WITH CHECK (true);
CREATE POLICY admin_all ON deliveries FOR ALL TO hh_admin USING (true) WITH CHECK (true);
CREATE POLICY admin_all ON settings   FOR ALL TO hh_admin USING (true) WITH CHECK (true);

-- The gateway only ever deals with externally-referenced candidates, and may
-- only see deliveries that are actually meant to go out.
CREATE POLICY gw_candidates ON candidates FOR SELECT TO bot_gateway USING (true);
CREATE POLICY gw_candidates_ins ON candidates FOR INSERT TO bot_gateway
    WITH CHECK (external_ref IS NOT NULL);
CREATE POLICY gw_candidates_upd ON candidates FOR UPDATE TO bot_gateway
    USING (external_ref IS NOT NULL) WITH CHECK (external_ref IS NOT NULL);

CREATE POLICY gw_resumes ON resumes FOR SELECT TO bot_gateway USING (true);
CREATE POLICY gw_resumes_ins ON resumes FOR INSERT TO bot_gateway
    WITH CHECK (source = 'gateway');

CREATE POLICY gw_deliveries ON deliveries FOR SELECT TO bot_gateway
    USING (status IN ('pending', 'sent'));
