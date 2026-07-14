<div id="qnap-sync-modal" class="sv-modal-backdrop hidden">
    <div class="sv-modal-card">
        <div class="sv-modal-header">
            <h3 class="sv-modal-title">
                <i class="bi bi-cloud-arrow-up-fill" style="color:var(--accent)"></i>
                Sync Recordings to Server
            </h3>
            <button class="sv-modal-close" onclick="closeSyncModal()">&times;</button>
        </div>
        <form id="qnap-sync-form" onsubmit="submitSyncForm(event)">
            <div class="sv-modal-body">

                {{-- Info banner --}}
                <div class="sv-form-group" style="background:var(--surface-2);border-radius:8px;padding:12px 16px;border:1px solid var(--border);margin-bottom:16px">
                    <div style="display:flex;align-items:center;gap:8px;color:var(--text-muted);font-size:0.85rem">
                        <i class="bi bi-info-circle-fill" style="color:var(--accent)"></i>
                        <span>Recordings will be uploaded from Jetson to this server, organized by device name and camera.</span>
                    </div>
                </div>

                <!-- Sync Options -->
                <div class="sv-form-group">
                    <label class="sv-label">Sync Scope</label>
                    <div class="sv-radio-group">
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="all" checked onchange="toggleScopeInputs()">
                            <span>All recordings</span>
                        </label>
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="today" onchange="toggleScopeInputs()">
                            <span>Only today's recordings</span>
                        </label>
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="last_n_days" onchange="toggleScopeInputs()">
                            <span>Only last N days:</span>
                            <input type="number" id="sync-days" class="sv-input-inline" value="7" min="1" disabled>
                        </label>
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="cameras" onchange="toggleScopeInputs()">
                            <span>Only specific cameras</span>
                        </label>
                    </div>
                </div>

                <!-- Cameras Selection (hidden by default) -->
                <div class="sv-form-group hidden" id="cameras-selection-row">
                    <label class="sv-label">Select Cameras</label>
                    <div class="sv-checkbox-group">
                        @foreach(config('surveillance.cameras', []) as $cam)
                            @if($cam['enabled'] ?? false)
                            <label class="sv-checkbox-label">
                                <input type="checkbox" name="sync-cameras" value="{{ $cam['id'] }}" checked>
                                <span>{{ $cam['label'] }}</span>
                            </label>
                            @endif
                        @endforeach
                    </div>
                </div>

                <!-- Extra Toggles -->
                <div class="sv-form-group checkbox-only">
                    <label class="sv-checkbox-label">
                        <input type="checkbox" id="delete-after-upload" checked>
                        <span>Delete local files after successful upload</span>
                    </label>
                    <label class="sv-checkbox-label">
                        <input type="checkbox" id="overwrite-existing">
                        <span>Overwrite existing files on server</span>
                    </label>
                </div>
            </div>
            <div class="sv-modal-footer">
                <div class="sv-modal-error hidden" id="qnap-error-msg"></div>
                <button type="button" class="sv-btn sv-btn-secondary" onclick="closeSyncModal()">Cancel</button>
                <button type="submit" class="sv-btn sv-btn-accent">
                    <i class="bi bi-play-fill"></i> Start Sync
                </button>
            </div>
        </form>
    </div>
</div>
