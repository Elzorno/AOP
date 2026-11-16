import './bootstrap';

import 'htmx.org';
import Alpine from 'alpinejs';
import { Calendar } from '@fullcalendar/core';
import interactionPlugin from '@fullcalendar/interaction';
import timeGridPlugin from '@fullcalendar/timegrid';

window.Alpine = Alpine;
window.AopCalendar = { Calendar, interactionPlugin, timeGridPlugin };

function conflictChecker(sectionId, blockId, previewUrl, csrfToken, initialBlockType = 'LECTURE') {
    return {
        conflicts: [],
        findings: [],
        days: [],
        startsAt: '',
        endsAt: '',
        roomId: '',
        blockType: initialBlockType,
        requestSequence: 0,
        timer: null,
        checking: false,

        get hasConflict() { return this.conflicts.length > 0; },
        get hasFinding() { return this.findings.length > 0; },
        get hasFeedback() { return this.hasConflict || this.hasFinding || this.checking; },

        check() {
            clearTimeout(this.timer);
            const sequence = ++this.requestSequence;
            this.checking = false;

            if (!this.startsAt || !this.endsAt || this.days.length === 0) {
                this.conflicts = [];
                this.findings = [];
                return;
            }

            if (this.endsAt <= this.startsAt) {
                this.conflicts = ['End time must be after start time.'];
                this.findings = [];
                return;
            }

            this.checking = true;
            this.timer = setTimeout(async () => {
                try {
                    const response = await fetch(previewUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            section_id: sectionId,
                            block_id: blockId,
                            days: this.days,
                            starts_at: this.startsAt,
                            ends_at: this.endsAt,
                            room_id: this.roomId || null,
                            type: this.blockType,
                        }),
                    });
                    const result = await response.json();
                    if (sequence !== this.requestSequence) return;

                    this.findings = result.valid ? (result.policy_findings ?? []) : [];
                    this.conflicts = result.valid
                        ? []
                        : [...new Set(Object.values(result.errors ?? {}).flat())];
                } catch (_error) {
                    if (sequence === this.requestSequence) {
                        this.conflicts = ['Validation preview is temporarily unavailable.'];
                        this.findings = [];
                    }
                } finally {
                    if (sequence === this.requestSequence) this.checking = false;
                }
            }, 250);
        },
    };
}

window.conflictChecker = conflictChecker;

Alpine.start();
