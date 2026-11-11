<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Medication integrity requires PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE medication_patient_settings (
 patient_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE RESTRICT,
 confirmed_timezone varchar(80) NOT NULL, confirmed_at timestamptz NOT NULL,
 version bigint NOT NULL DEFAULT 1 CHECK(version > 0)
);
CREATE TABLE medications (
 id uuid PRIMARY KEY, patient_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 version bigint NOT NULL DEFAULT 1 CHECK(version > 0),
 created_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 updated_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 archived_by bigint REFERENCES users(id) ON DELETE RESTRICT, archived_at timestamptz,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(id,patient_id), CHECK ((archived_at IS NULL) = (archived_by IS NULL))
);
CREATE INDEX medication_patient_list ON medications(patient_id,archived_at,created_at,id);
CREATE TABLE medication_schedule_revisions (
 id uuid PRIMARY KEY, medication_id uuid NOT NULL, patient_id bigint NOT NULL,
 revision_number integer NOT NULL CHECK(revision_number > 0),
 prescription jsonb NOT NULL CHECK(jsonb_typeof(prescription)='object'),
 recurrence_snapshot jsonb NOT NULL,
 frequency varchar(24) NOT NULL CHECK(frequency IN ('once_daily','twice_daily','three_times_daily','specific_days','every_hours','as_needed')),
 timezone varchar(80) NOT NULL, start_date date NOT NULL, end_date date,
 interval_hours integer, anchor_time time(0), anchor_at timestamptz,
 effective_from timestamptz NOT NULL, effective_to timestamptz, superseded_at timestamptz,
 grace_policy_version integer NOT NULL DEFAULT 1 CHECK(grace_policy_version > 0),
 grace_seconds integer NOT NULL DEFAULT 1800 CHECK(grace_seconds > 0),
 generated_through timestamptz,
 created_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, created_at timestamptz NOT NULL,
 UNIQUE(medication_id,revision_number), UNIQUE(id,medication_id,patient_id),
 FOREIGN KEY(medication_id,patient_id) REFERENCES medications(id,patient_id) ON DELETE RESTRICT,
 CHECK(end_date IS NULL OR end_date >= start_date),
 CHECK(effective_to IS NULL OR effective_to >= effective_from),
 CHECK((frequency='every_hours' AND interval_hours BETWEEN 1 AND 168 AND anchor_time IS NOT NULL AND anchor_at IS NOT NULL)
 OR (frequency<>'every_hours' AND interval_hours IS NULL AND anchor_time IS NULL AND anchor_at IS NULL)),
 CHECK(prescription ?& ARRAY['name','dose_amount','dose_unit','form','relation_to_meals','instructions','special_notes']),
 CHECK(length(prescription->>'name') BETWEEN 1 AND 160),
 CHECK((prescription->>'dose_amount')::numeric > 0),
 CHECK(prescription->>'dose_unit' IN ('mg','g','ml','mcg','IU','tablet','capsule','drop','puff')),
 CHECK(prescription->>'form' IN ('tablet','capsule','syrup','drops','injection','inhaler','cream','other')),
 CHECK(prescription->>'relation_to_meals' IN ('before_food','after_food','with_food','no_preference'))
);
CREATE INDEX medication_revision_effective ON medication_schedule_revisions(patient_id,effective_from,effective_to);
CREATE TABLE medication_schedule_times (
 revision_id uuid NOT NULL REFERENCES medication_schedule_revisions(id) ON DELETE RESTRICT,
 local_time time(0) NOT NULL, PRIMARY KEY(revision_id,local_time)
);
CREATE TABLE medication_schedule_weekdays (
 revision_id uuid NOT NULL REFERENCES medication_schedule_revisions(id) ON DELETE RESTRICT,
 weekday smallint NOT NULL CHECK(weekday BETWEEN 1 AND 7), PRIMARY KEY(revision_id,weekday)
);
CREATE TABLE medication_dose_occurrences (
 id uuid PRIMARY KEY, medication_id uuid NOT NULL, patient_id bigint NOT NULL, revision_id uuid NOT NULL,
 kind varchar(12) NOT NULL CHECK(kind IN ('scheduled','prn')),
 scheduled_at timestamptz, missed_at timestamptz, local_date date NOT NULL,
 local_time time(0), timezone varchar(80) NOT NULL, resolved_local_time varchar(40),
 requested_local_times jsonb NOT NULL DEFAULT '[]',
 resolution varchar(12) CHECK(resolution IN ('taken','skipped','void')),
 actual_intake_at timestamptz, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 cancelled_at timestamptz, created_at timestamptz NOT NULL,
 UNIQUE(id,medication_id,patient_id), UNIQUE(revision_id,scheduled_at),
 FOREIGN KEY(revision_id,medication_id,patient_id) REFERENCES medication_schedule_revisions(id,medication_id,patient_id) ON DELETE RESTRICT,
 CHECK((kind='scheduled' AND scheduled_at IS NOT NULL AND missed_at IS NOT NULL AND missed_at>scheduled_at AND local_time IS NOT NULL AND resolution IS DISTINCT FROM 'void')
 OR (kind='prn' AND scheduled_at IS NULL AND missed_at IS NULL AND resolution IN ('taken','void'))),
 CHECK((resolution IS NOT DISTINCT FROM 'taken') = (actual_intake_at IS NOT NULL)),
 CHECK(kind<>'prn' OR resolution IS NOT NULL)
);
CREATE UNIQUE INDEX medication_live_instant ON medication_dose_occurrences(medication_id,scheduled_at) WHERE cancelled_at IS NULL AND kind='scheduled';
CREATE INDEX medication_day ON medication_dose_occurrences(patient_id,scheduled_at,id);
CREATE INDEX medication_unresolved ON medication_dose_occurrences(missed_at,id) WHERE resolution IS NULL AND cancelled_at IS NULL;
CREATE TABLE medication_intake_events (
 id uuid PRIMARY KEY, sequence bigint GENERATED ALWAYS AS IDENTITY UNIQUE, occurrence_id uuid NOT NULL, medication_id uuid NOT NULL, patient_id bigint NOT NULL,
 actor_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 event_type varchar(20) NOT NULL CHECK(event_type IN ('missed','taken','skipped','corrected','prn_taken','prn_corrected','prn_voided')),
 previous_resolution varchar(12), new_resolution varchar(12), actual_intake_at timestamptz,
 effective_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL, reason varchar(1000),
 FOREIGN KEY(occurrence_id,medication_id,patient_id) REFERENCES medication_dose_occurrences(id,medication_id,patient_id) ON DELETE RESTRICT,
 CHECK((event_type='missed') = (actor_id IS NULL)), CHECK(actor_id IS NULL OR actor_id=patient_id)
);
CREATE UNIQUE INDEX medication_one_missed_event ON medication_intake_events(occurrence_id) WHERE event_type='missed';
CREATE INDEX medication_intake_history ON medication_intake_events(patient_id,occurrence_id,recorded_at,id);
CREATE TABLE medication_preferences (
 medication_id uuid PRIMARY KEY, patient_id bigint NOT NULL,
 enable_medication_reminders boolean NOT NULL DEFAULT true,
 notify_caregiver_if_missed boolean NOT NULL DEFAULT false,
 escalation_delay_minutes integer NOT NULL DEFAULT 30 CHECK(escalation_delay_minutes IN (15,30,60)),
 consent_by bigint REFERENCES users(id) ON DELETE RESTRICT, consent_at timestamptz,
 consent_version bigint NOT NULL DEFAULT 1, version bigint NOT NULL DEFAULT 1,
 FOREIGN KEY(medication_id,patient_id) REFERENCES medications(id,patient_id) ON DELETE RESTRICT,
 CHECK(consent_by IS NULL OR consent_by=patient_id),
 CHECK(NOT notify_caregiver_if_missed OR (consent_by=patient_id AND consent_at IS NOT NULL))
);
CREATE TABLE medication_notification_candidates (
 id uuid PRIMARY KEY, occurrence_id uuid NOT NULL, medication_id uuid NOT NULL, patient_id bigint NOT NULL,
 candidate_type varchar(12) NOT NULL CHECK(candidate_type IN ('reminder','escalation')),
 recipient_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 relationship_id uuid REFERENCES caregiver_links(id) ON DELETE RESTRICT,
 eligible_at timestamptz NOT NULL, consent_version bigint NOT NULL,
 state varchar(12) NOT NULL CHECK(state IN ('eligible','suppressed')), reason varchar(80),
 created_at timestamptz NOT NULL, evaluated_at timestamptz NOT NULL,
 FOREIGN KEY(occurrence_id,medication_id,patient_id) REFERENCES medication_dose_occurrences(id,medication_id,patient_id) ON DELETE RESTRICT,
 CHECK((candidate_type='escalation') = (relationship_id IS NOT NULL))
);
CREATE UNIQUE INDEX medication_candidate_identity ON medication_notification_candidates(occurrence_id,candidate_type,recipient_id,COALESCE(relationship_id,'00000000-0000-0000-0000-000000000000'::uuid));
CREATE INDEX medication_candidate_due ON medication_notification_candidates(state,eligible_at,id);
CREATE TABLE medication_audit_events (
 id uuid PRIMARY KEY, patient_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 medication_id uuid, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 action varchar(40) NOT NULL, changes jsonb NOT NULL, recorded_at timestamptz NOT NULL,
 FOREIGN KEY(medication_id,patient_id) REFERENCES medications(id,patient_id) ON DELETE RESTRICT
);
CREATE INDEX medication_audit_history ON medication_audit_events(patient_id,medication_id,recorded_at,id);
CREATE TABLE medication_mutation_requests (
 actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, request_key uuid NOT NULL,
 request_hash char(64) NOT NULL, response jsonb NOT NULL, status smallint NOT NULL,
 recorded_at timestamptz NOT NULL, PRIMARY KEY(actor_id,request_key)
);
CREATE FUNCTION medication_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Medication history is append-only' USING ERRCODE='23514'; END $$;
CREATE TRIGGER medication_immutable_intake BEFORE UPDATE OR DELETE ON medication_intake_events FOR EACH ROW EXECUTE FUNCTION medication_append_only();
CREATE TRIGGER medication_immutable_audit BEFORE UPDATE OR DELETE ON medication_audit_events FOR EACH ROW EXECUTE FUNCTION medication_append_only();
CREATE FUNCTION medication_patient_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.patient_id AND role='patient') THEN
 RAISE EXCEPTION 'Medication owner must be a patient' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND NEW.patient_id<>OLD.patient_id THEN RAISE EXCEPTION 'Medication ownership is immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER medication_owner BEFORE INSERT OR UPDATE ON medications FOR EACH ROW EXECUTE FUNCTION medication_patient_owner();
CREATE TRIGGER medication_settings_owner BEFORE INSERT OR UPDATE ON medication_patient_settings FOR EACH ROW EXECUTE FUNCTION medication_patient_owner();
CREATE FUNCTION medication_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM 1 FROM medications WHERE id=NEW.medication_id FOR UPDATE;
 IF TG_OP='UPDATE' AND (to_jsonb(NEW)-ARRAY['effective_to','superseded_at','generated_through']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['effective_to','superseded_at','generated_through']) THEN
 RAISE EXCEPTION 'Prescription revisions are immutable' USING ERRCODE='23514'; END IF;
 IF NEW.superseded_at IS NULL AND EXISTS(SELECT 1 FROM medication_schedule_revisions r WHERE r.medication_id=NEW.medication_id AND r.id<>NEW.id AND r.superseded_at IS NULL AND tstzrange(r.effective_from,r.effective_to,'[)') && tstzrange(NEW.effective_from,NEW.effective_to,'[)')) THEN
 RAISE EXCEPTION 'Overlapping medication revisions' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER medication_revision_integrity BEFORE INSERT OR UPDATE ON medication_schedule_revisions FOR EACH ROW EXECUTE FUNCTION medication_revision_guard();
CREATE FUNCTION medication_recurrence_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rid uuid; f text; tc integer; dc integer; snapshot jsonb; ts jsonb; ds jsonb;
BEGIN
 IF TG_TABLE_NAME='medication_schedule_revisions' THEN rid:=NEW.id; ELSE rid:=NEW.revision_id; END IF;
 SELECT frequency,recurrence_snapshot INTO f,snapshot FROM medication_schedule_revisions WHERE id=rid;
 SELECT count(*) INTO tc FROM medication_schedule_times WHERE revision_id=rid;
 SELECT count(*) INTO dc FROM medication_schedule_weekdays WHERE revision_id=rid;
 SELECT COALESCE(jsonb_agg(to_char(local_time,'HH24:MI') ORDER BY local_time),'[]'::jsonb) INTO ts FROM medication_schedule_times WHERE revision_id=rid;
 SELECT COALESCE(jsonb_agg(weekday ORDER BY weekday),'[]'::jsonb) INTO ds FROM medication_schedule_weekdays WHERE revision_id=rid;
 IF ts IS DISTINCT FROM snapshot->'times' OR ds IS DISTINCT FROM snapshot->'weekdays' THEN RAISE EXCEPTION 'Recurrence snapshot is immutable' USING ERRCODE='23514'; END IF;
 IF (f='once_daily' AND tc<>1) OR (f='twice_daily' AND tc<>2) OR (f='three_times_daily' AND tc<>3)
 OR (f='specific_days' AND (tc<1 OR tc>24 OR dc<1)) OR (f IN ('every_hours','as_needed') AND tc<>0)
 OR (f<>'specific_days' AND dc<>0) THEN RAISE EXCEPTION 'Invalid medication recurrence' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER medication_recurrence_revision AFTER INSERT OR UPDATE ON medication_schedule_revisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION medication_recurrence_check();
CREATE CONSTRAINT TRIGGER medication_recurrence_time AFTER INSERT OR UPDATE ON medication_schedule_times DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION medication_recurrence_check();
CREATE CONSTRAINT TRIGGER medication_recurrence_day AFTER INSERT OR UPDATE ON medication_schedule_weekdays DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION medication_recurrence_check();
CREATE TRIGGER medication_time_immutable BEFORE UPDATE OR DELETE ON medication_schedule_times FOR EACH ROW EXECUTE FUNCTION medication_append_only();
CREATE TRIGGER medication_day_immutable BEFORE UPDATE OR DELETE ON medication_schedule_weekdays FOR EACH ROW EXECUTE FUNCTION medication_append_only();
SQL);
    }

    public function down(): void
    {
        // Deliberate rollback only; never used against development data by automated tests.
        DB::unprepared('DROP TABLE medication_mutation_requests, medication_audit_events, medication_notification_candidates, medication_preferences, medication_intake_events, medication_dose_occurrences, medication_schedule_weekdays, medication_schedule_times, medication_schedule_revisions, medications, medication_patient_settings; DROP FUNCTION medication_recurrence_check(), medication_revision_guard(), medication_patient_owner(), medication_append_only();');
    }
};
