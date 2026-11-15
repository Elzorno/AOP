import './bootstrap';

import 'htmx.org';
import Alpine from 'alpinejs';
import { Calendar } from '@fullcalendar/core';
import interactionPlugin from '@fullcalendar/interaction';
import timeGridPlugin from '@fullcalendar/timegrid';

window.Alpine = Alpine;
window.AopCalendar = { Calendar, interactionPlugin, timeGridPlugin };

function conflictChecker(sectionId, blockId, previewUrl, csrfToken) {
    return {
        conflicts: [],
        days: [],
        startsAt: '',
        endsAt: '',
        roomId: '',
        requestSequence: 0,
        timer: null,

        get hasConflict() { return this.conflicts.length > 0; },

        check() {
            clearTimeout(this.timer);

            if (!this.startsAt || !this.endsAt || this.days.length === 0) {
                this.conflicts = [];
                return;
            }

            if (this.endsAt <= this.startsAt) {
                this.conflicts = ['End time must be after start time.'];
                return;
            }

            const sequence = ++this.requestSequence;
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
                        }),
                    });
                    const result = await response.json();
                    if (sequence !== this.requestSequence) return;

                    this.conflicts = result.valid
                        ? []
                        : [...new Set(Object.values(result.errors ?? {}).flat())];
                } catch (_error) {
                    if (sequence === this.requestSequence) {
                        this.conflicts = ['Validation preview is temporarily unavailable.'];
                    }
                }
            }, 250);
        },
    };
}

window.conflictChecker = conflictChecker;

Alpine.start();
