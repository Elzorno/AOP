import './bootstrap';

import 'htmx.org';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Client-side conflict checker for meeting block forms.
 * Reads the serialized block list from #sched-blocks-data and checks
 * for room or instructor overlaps as the user fills in the form.
 *
 * @param {number} sectionId     - The section this form belongs to (its blocks are excluded).
 * @param {number|null} instructorId - The instructor assigned to this section (may be null).
 */
function conflictChecker(sectionId, instructorId) {
    return {
        conflicts: [],
        days: [],
        startsAt: '',
        endsAt: '',
        roomId: '',

        get hasConflict() { return this.conflicts.length > 0; },

        check() {
            const allBlocks = JSON.parse(
                document.getElementById('sched-blocks-data')?.dataset?.blocks ?? '[]'
            );

            if (!this.startsAt || !this.endsAt || this.days.length === 0) {
                this.conflicts = [];
                return;
            }

            const found = [];
            for (const b of allBlocks) {
                // Skip blocks belonging to this same section
                if (b.section_id === sectionId) continue;

                // Check day overlap
                const dayOverlap = b.days.some(d => this.days.includes(d));
                if (!dayOverlap) continue;

                // Check time overlap using HH:MM string comparison
                const aStart = this.startsAt, aEnd = this.endsAt;
                const bStart = b.starts_at,   bEnd = b.ends_at;
                const overlaps = aStart < bEnd && aEnd > bStart;
                if (!overlaps) continue;

                // Room conflict
                if (this.roomId && b.room_id && String(b.room_id) === String(this.roomId)) {
                    found.push('Room conflict with another section');
                }
                // Instructor conflict
                if (instructorId && b.instructor_id && b.instructor_id === instructorId) {
                    found.push('Instructor conflict with another section');
                }
            }

            // Deduplicate messages
            this.conflicts = [...new Set(found)];
        },
    };
}

window.conflictChecker = conflictChecker;

Alpine.start();
