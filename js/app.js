// app.js
// Main frontend client for BJS webapp. Handles routing, data loading, Chart.js,
// real-time JS calculations, and AJAX auto-saving.

document.addEventListener('DOMContentLoaded', () => {
    // App State
    let activeView = 'dashboard';
    let currentClass = '';
    let studentsList = [];
    let selectedStudent = null;
    let autoSaveTimeout = null;
    let charts = {};
    let lastOverview = null;
    let lastClassStats = null;
    let classesLoaded = false;
    // Offline / Sync Queue state
    let syncQueue = JSON.parse(localStorage.getItem('bjs_sync_queue') || '{}');
    let syncStatusBadge = document.getElementById('sync-status-badge');
    let syncStatusText = document.getElementById('sync-status-text');
    let isSyncing = false;

    // Theme Switcher Initialization
    const themeToggle = document.getElementById('theme-toggle');
    const themeToggleText = document.getElementById('theme-toggle-text');
    
    const savedTheme = localStorage.getItem('bjs_theme') || 'dark';
    if (savedTheme === 'light') {
        document.documentElement.classList.add('light-theme');
        if (themeToggleText) themeToggleText.textContent = '🌙 Dunkles Design';
    } else {
        document.documentElement.classList.remove('light-theme');
        if (themeToggleText) themeToggleText.textContent = '☀️ Helles Design';
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isLightNow = document.documentElement.classList.toggle('light-theme');
            localStorage.setItem('bjs_theme', isLightNow ? 'light' : 'dark');
            
            if (themeToggleText) {
                themeToggleText.textContent = isLightNow ? '🌙 Dunkles Design' : '☀️ Helles Design';
            }
            
            // Re-render charts to adjust text/grid colors dynamically
            if (activeView === 'dashboard' && lastOverview && lastClassStats) {
                renderCharts(lastOverview, lastClassStats);
            }
        });
    }

    // DOM Elements
    const navItems = document.querySelectorAll('.nav-item');
    const views = document.querySelectorAll('.content-view');
    const pageTitle = document.getElementById('page-title');
    const classSelect = document.getElementById('class-select');
    const studentSearch = document.getElementById('student-search');
    const studentListUl = document.getElementById('student-list-ul');
    
    // Form elements
    const formEmptyState = document.getElementById('form-empty-state');
    const formActiveState = document.getElementById('form-active-state');
    const formAvatar = document.getElementById('form-avatar');
    const formStudentName = document.getElementById('form-student-name');
    const formStudentMeta = document.getElementById('form-student-meta');
    const inputStudentId = document.getElementById('input-student-id');
    const labelSprint = document.getElementById('label-sprint');
    
    // Inputs
    const inputSprint = document.getElementById('input-sprint');
    const inputSprung = document.getElementById('input-sprung');
    const inputWurf = document.getElementById('input-wurf');
    const inputAusdauer = document.getElementById('input-ausdauer');
    
    // Form Point Outputs
    const pointsSprint = document.getElementById('calc-points-sprint');
    const pointsSprung = document.getElementById('calc-points-sprung');
    const pointsWurf = document.getElementById('calc-points-wurf');
    const pointsAusdauer = document.getElementById('calc-points-ausdauer');
    
    // Evaluation Card Outputs
    const liveTotalPoints = document.getElementById('live-total-points');
    const liveUrkundeBadge = document.getElementById('live-urkunde-badge');
    const liveGrade = document.getElementById('live-grade');
    
    // Status & Buttons
    const saveStatus = document.getElementById('save-status');
    const btnNextStudent = document.getElementById('btn-next-student');
    const btnResetDb = document.getElementById('btn-reset-db');

    // Global fetch interceptor to handle session timeouts (401 Unauthorized)
    const originalFetch = window.fetch;
    window.fetch = async function(...args) {
        try {
            const response = await originalFetch(...args);
            if (response.status === 401) {
                window.location.reload();
            }
            return response;
        } catch (error) {
            throw error;
        }
    };

    // ==========================================
    // 1. SPA ROUTING
    // ==========================================
    function handleRouting() {
        const hash = window.location.hash || '#dashboard';
        const targetViewId = hash.replace('#', 'view-');
        
        // Hide all views, show target view
        views.forEach(v => v.classList.remove('active-view'));
        const targetView = document.getElementById(targetViewId);
        if (targetView) {
            targetView.classList.add('active-view');
        }

        // Update nav items
        navItems.forEach(item => {
            if (item.getAttribute('href') === hash) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });

        // Update title and trigger actions
        activeView = hash.replace('#', '');
        pageTitle.textContent = activeView.charAt(0).toUpperCase() + activeView.slice(1);
        if (activeView === 'eingabe') {
            pageTitle.textContent = 'Daten-Eingabe';
            loadClasses();
        } else if (activeView === 'dashboard') {
            loadDashboard();
        } else if (activeView === 'admin') {
            pageTitle.textContent = 'Verwaltung';
        }
    }

    window.addEventListener('hashchange', handleRouting);
    handleRouting(); // Initial routing

    // ==========================================
    // 2. TOAST SYSTEM
    // ==========================================
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        let icon = 'ℹ️';
        if (type === 'success') icon = '✅';
        if (type === 'error') icon = '❌';
        
        toast.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-msg">${message}</span>`;
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideIn 0.3s reverse forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ==========================================
    // 3. REAL-TIME BJS CALCULATIONS (JS ENGINE)
    // ==========================================
    function timeToSeconds(timeStr) {
        if (!timeStr) return null;
        timeStr = timeStr.trim();
        
        // Handle dots/commas as separators (e.g. 4.25 -> 4 mins 25 secs)
        timeStr = timeStr.replace(',', '.');
        
        if (timeStr.includes(':')) {
            const parts = timeStr.split(':');
            if (parts.length === 2) {
                return parseInt(parts[0], 10) * 60 + parseFloat(parts[1]);
            }
        } else if (timeStr.includes('.')) {
            const parts = timeStr.split('.');
            if (parts.length === 2) {
                // If it's a decimal format typed on tablet like '4.35'
                return parseInt(parts[0], 10) * 60 + parseFloat(parts[1]);
            }
        }
        return parseFloat(timeStr);
    }

    function lookupPoints(gender, age, stufe, event, val) {
        if (val === null || val === undefined || val === '' || val === 0 || val === 0.0) {
            return 0;
        }

        const is56 = (stufe === '5' || stufe === '6');
        const genderSuffix = (gender.toUpperCase() === 'W') ? 'w' : 'm';
        const tableKey = is56 ? `punkte_${genderSuffix}_5-6` : `punkte_${genderSuffix}_7-9`;
        
        const list = BJSRules[tableKey]?.[event];
        if (!list || list.length === 0) return 0;

        if (event === 'ausdauer') {
            const valSec = timeToSeconds(val);
            if (valSec === null || isNaN(valSec)) return 0;
            
            let bestMatch = null;
            let minLargerLeistung = null;
            
            for (const item of list) {
                const itemSec = timeToSeconds(item.leistung);
                if (itemSec === null || isNaN(itemSec)) continue;
                
                if (itemSec === valSec) {
                    return Math.round(item.punkte);
                }
                
                // Lower time is better (so next larger time corresponds to achieved points)
                if (itemSec > valSec) {
                    if (minLargerLeistung === null || itemSec < minLargerLeistung) {
                        minLargerLeistung = itemSec;
                        bestMatch = item;
                    }
                }
            }
            return bestMatch ? Math.round(bestMatch.punkte) : 0;
            
        } else if (event === 'sprint') {
            const valFloat = parseFloat(val);
            if (isNaN(valFloat)) return 0;
            
            let bestMatch = null;
            let minLargerLeistung = null;
            
            for (const item of list) {
                const itemVal = parseFloat(item.leistung);
                if (itemVal === valFloat) {
                    return Math.round(item.punkte);
                }
                if (itemVal > valFloat) {
                    if (minLargerLeistung === null || itemVal < minLargerLeistung) {
                        minLargerLeistung = itemVal;
                        bestMatch = item;
                    }
                }
            }
            return bestMatch ? Math.round(bestMatch.punkte) : 0;
            
        } else if (event === 'sprung' || event === 'wurf') {
            const valFloat = parseFloat(val);
            if (isNaN(valFloat)) return 0;
            
            let bestMatch = null;
            let maxSmallerLeistung = null;
            
            for (const item of list) {
                const itemVal = parseFloat(item.leistung);
                if (itemVal === valFloat) {
                    return Math.round(item.punkte);
                }
                if (itemVal < valFloat) {
                    if (maxSmallerLeistung === null || itemVal > maxSmallerLeistung) {
                        maxSmallerLeistung = itemVal;
                        bestMatch = item;
                    }
                }
            }
            return bestMatch ? Math.round(bestMatch.punkte) : 0;
        }

        return 0;
    }

    function calculateLocalResults(student, ausdauer, sprint, sprung, wurf) {
        const age = 2026 - parseInt(student.geburtsjahr, 10);
        const gender = student.geschlecht.toUpperCase();
        const stufe = student.klasse.length >= 2 ? student.klasse.charAt(1) : '';
        
        // Lookup individual points
        const pAusdauer = lookupPoints(gender, age, stufe, 'ausdauer', ausdauer);
        const pSprint = lookupPoints(gender, age, stufe, 'sprint', sprint);
        const pSprung = lookupPoints(gender, age, stufe, 'sprung', sprung);
        const pWurf = lookupPoints(gender, age, stufe, 'wurf', wurf);
        
        // Count participations
        let totalParticipations = 0;
        if (ausdauer !== null && ausdauer !== '') totalParticipations++;
        if (sprint !== null && sprint !== '') totalParticipations++;
        if (sprung !== null && sprung !== '') totalParticipations++;
        if (wurf !== null && wurf !== '') totalParticipations++;
        
        if (totalParticipations === 0) {
            return {
                p_ausdauer: 0,
                p_sprint: 0,
                p_sprung: 0,
                p_wurf: 0,
                gesamt: 0,
                urkunde: 'Keine Teilnahme',
                note: 'Keine Teilnahme'
            };
        }
        
        // Gesamt points: best 3 of 4
        const pts = [pAusdauer, pSprint, pSprung, pWurf];
        const gesamt = pts.reduce((sum, p) => sum + p, 0) - Math.min(...pts);
        
        // Urkunde boundary check
        const code = age + gender.toLowerCase();
        let sMin = Infinity;
        let eMin = Infinity;
        
        for (const f of BJSRules.formeln) {
            if (f.code === code) {
                sMin = parseFloat(f.s_min);
                eMin = parseFloat(f.e_min);
                break;
            }
        }
        
        let urkunde = 'Teilnehmerurkunde';
        if (gesamt >= eMin) {
            urkunde = 'Ehrenurkunde';
        } else if (gesamt >= sMin) {
            urkunde = 'Siegerurkunde';
        }
        
        // Grade (Note) lookup
        const matrixKey = (gender === 'W') ? 'noten_maedchen' : 'noten_jungen';
        const notenData = BJSRules[matrixKey];
        let note = 'Keine Note';
        
        if (gesamt === 0) {
            note = 'Keine Teilnahme';
        } else if (notenData) {
            const ageStr = age.toFixed(1);
            let bestNote = null;
            let maxSmallerPoints = null;
            
            for (const row of notenData.matrix) {
                if (row.points[ageStr] !== undefined && row.points[ageStr] !== null) {
                    const rowPts = parseFloat(row.points[ageStr]);
                    
                    if (rowPts === gesamt) {
                        note = row.note;
                        break;
                    }
                    if (rowPts < gesamt) {
                        if (maxSmallerPoints === null || rowPts > maxSmallerPoints) {
                            maxSmallerPoints = rowPts;
                            bestNote = row.note;
                        }
                    }
                }
            }
            if (bestNote !== null && note === 'Keine Note') {
                note = bestNote;
            }
        }
        
        return {
            p_ausdauer: pAusdauer,
            p_sprint: pSprint,
            p_sprung: pSprung,
            p_wurf: pWurf,
            gesamt: gesamt,
            urkunde: urkunde,
            note: note
        };
    }

    function updateLiveCalculationUI() {
        if (!selectedStudent) return;
        
        const ausdauer = inputAusdauer.value;
        const sprint = inputSprint.value;
        const sprung = inputSprung.value;
        const wurf = inputWurf.value;
        
        const calc = calculateLocalResults(selectedStudent, ausdauer, sprint, sprung, wurf);
        
        // Update individual points in fields
        updateFieldPoints(pointsAusdauer, calc.p_ausdauer, ausdauer);
        updateFieldPoints(pointsSprint, calc.p_sprint, sprint);
        updateFieldPoints(pointsSprung, calc.p_sprung, sprung);
        updateFieldPoints(pointsWurf, calc.p_wurf, wurf);
        
        // Update Live Card
        liveTotalPoints.textContent = calc.gesamt;
        liveGrade.textContent = calc.note;
        
        // Urkunde badge styling
        liveUrkundeBadge.textContent = calc.urkunde;
        liveUrkundeBadge.className = 'badge';
        if (calc.urkunde === 'Ehrenurkunde') {
            liveUrkundeBadge.classList.add('badge-gold');
        } else if (calc.urkunde === 'Siegerurkunde') {
            liveUrkundeBadge.classList.add('badge-green');
        } else if (calc.urkunde === 'Teilnehmerurkunde') {
            liveUrkundeBadge.classList.add('badge-orange');
        } else {
            liveUrkundeBadge.classList.add('badge-gray');
        }

        // Cross-out lowest event points if student participated in all 4
        const pts = [
            { el: pointsAusdauer, val: calc.p_ausdauer, active: ausdauer !== '' },
            { el: pointsSprint, val: calc.p_sprint, active: sprint !== '' },
            { el: pointsSprung, val: calc.p_sprung, active: sprung !== '' },
            { el: pointsWurf, val: calc.p_wurf, active: wurf !== '' }
        ];
        
        const activePts = pts.filter(p => p.active);
        pts.forEach(p => p.el.style.textDecoration = 'none');
        document.getElementById('live-lowest-dropped-msg').style.display = 'none';

        if (activePts.length === 4) {
            // Find the lowest points of the active events to cross it out visually
            let lowestVal = Math.min(...activePts.map(p => p.val));
            let crossedOut = false;
            // Cross out only one event with lowest points
            for (const p of activePts) {
                if (p.val === lowestVal && !crossedOut) {
                    p.el.style.textDecoration = 'line-through';
                    p.el.style.opacity = '0.5';
                    crossedOut = true;
                } else {
                    p.el.style.opacity = '1';
                }
            }
            document.getElementById('live-lowest-dropped-msg').style.display = 'block';
        } else {
            pts.forEach(p => p.el.style.opacity = '1');
        }
    }

    function updateFieldPoints(element, points, rawVal) {
        if (rawVal === null || rawVal === '') {
            element.textContent = '0 Punkte';
            element.classList.remove('active');
        } else {
            element.textContent = `${points} Punkte`;
            element.classList.add('active');
        }
    }

    // Bind real-time recalculation event listeners
    const fields = [inputSprint, inputSprung, inputWurf, inputAusdauer];
    fields.forEach(f => {
        f.addEventListener('input', () => {
            updateLiveCalculationUI();
            triggerAutoSave();
        });
    });

    // ==========================================
    // 4. AUTO-SAVE VIA AJAX
    // ==========================================
    function triggerAutoSave() {
        if (!selectedStudent) return;
        
        // Show saving... status
        saveStatus.className = 'auto-save-indicator visible saving';
        saveStatus.querySelector('.save-status-text').textContent = 'Speichert...';
        
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
        }
        
        autoSaveTimeout = setTimeout(performSave, 800); // 800ms debounce
    }

    function updateSyncStatusUI() {
        if (!syncStatusBadge || !syncStatusText) return;
        const queueSize = Object.keys(syncQueue).length;
        if (!navigator.onLine) {
            syncStatusBadge.className = 'sync-status-badge status-offline';
            if (queueSize > 0) {
                syncStatusText.textContent = `Offline (${queueSize} unsync.)`;
            } else {
                syncStatusText.textContent = 'Offline';
            }
        } else if (queueSize > 0) {
            syncStatusBadge.className = 'sync-status-badge status-syncing';
            syncStatusText.textContent = `Syncing (${queueSize})...`;
        } else {
            syncStatusBadge.className = 'sync-status-badge status-online';
            syncStatusText.textContent = 'Online';
        }
    }

    window.addEventListener('online', () => {
        updateSyncStatusUI();
        syncData();
    });
    window.addEventListener('offline', () => {
        updateSyncStatusUI();
    });

    function syncData() {
        if (isSyncing || !navigator.onLine) return;
        
        const pendingIds = Object.keys(syncQueue);
        if (pendingIds.length === 0) {
            updateSyncStatusUI();
            return;
        }
        
        isSyncing = true;
        updateSyncStatusUI();
        
        const nextId = pendingIds[0];
        const payload = syncQueue[nextId];
        
        fetch('api.php?action=save_result', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                delete syncQueue[nextId];
                localStorage.setItem('bjs_sync_queue', JSON.stringify(syncQueue));
                
                if (selectedStudent && selectedStudent.id == nextId) {
                    saveStatus.className = 'auto-save-indicator visible';
                    saveStatus.querySelector('.save-status-text').textContent = 'Gespeichert';
                }
            } else {
                console.warn('Sync server error for student', nextId, res.error);
            }
        })
        .catch(err => {
            console.error('Sync network error:', err);
        })
        .finally(() => {
            isSyncing = false;
            updateSyncStatusUI();
            
            if (Object.keys(syncQueue).length > 0 && navigator.onLine) {
                setTimeout(syncData, 300);
            }
        });
    }

    // Run sync check on startup and periodically
    updateSyncStatusUI();
    syncData();
    setInterval(syncData, 5000);

    function performSave() {
        if (!selectedStudent) return;
        
        const studentId = selectedStudent.id;
        const payload = {
            student_id: studentId,
            ausdauer: inputAusdauer.value,
            sprint: inputSprint.value,
            sprung: inputSprung.value,
            wurf: inputWurf.value
        };
        
        // Overwrite and recalculate locally immediately for instant list display
        const calc = calculateLocalResults(selectedStudent, payload.ausdauer, payload.sprint, payload.sprung, payload.wurf);
        
        const index = studentsList.findIndex(s => s.id === studentId);
        if (index !== -1) {
            studentsList[index].ausdauer_leistung = payload.ausdauer;
            studentsList[index].sprint_leistung = payload.sprint;
            studentsList[index].sprung_leistung = payload.sprung;
            studentsList[index].wurf_leistung = payload.wurf;
            studentsList[index].gesamt_punkte = calc.gesamt;
            studentsList[index].urkunde = calc.urkunde;
            studentsList[index].note = calc.note;
            
            updateStudentRowBadge(studentId, calc);
        }
        
        // Cache input locally
        localStorage.setItem(`bjs_student_input_${studentId}`, JSON.stringify(payload));
        
        // Add to sync queue
        syncQueue[studentId] = payload;
        localStorage.setItem('bjs_sync_queue', JSON.stringify(syncQueue));
        
        // Visual offline/local indicator
        saveStatus.className = 'auto-save-indicator visible';
        saveStatus.querySelector('.save-status-text').textContent = 'Lokal gesichert';
        
        updateSyncStatusUI();
        
        // Attempt sync
        syncData();
    }

    function updateStudentRowBadge(studentId, calc) {
        const li = document.querySelector(`.student-list li[data-id="${studentId}"]`);
        if (!li) return;
        
        const badgeContainer = li.querySelector('.badge-container');
        if (!badgeContainer) return;
        
        let totalParticipations = 0;
        const inputState = syncQueue[studentId] || {
            ausdauer: inputAusdauer.value,
            sprint: inputSprint.value,
            sprung: inputSprung.value,
            wurf: inputWurf.value
        };
        
        if (inputState.ausdauer !== '') totalParticipations++;
        if (inputState.sprint !== '') totalParticipations++;
        if (inputState.sprung !== '') totalParticipations++;
        if (inputState.wurf !== '') totalParticipations++;
        
        badgeContainer.innerHTML = '';
        if (totalParticipations === 0) {
            badgeContainer.innerHTML = '<span class="badge badge-gray">Ausstehend</span>';
        } else {
            let badgeClass = 'badge-orange';
            if (calc.urkunde === 'Ehrenurkunde') badgeClass = 'badge-gold';
            if (calc.urkunde === 'Siegerurkunde') badgeClass = 'badge-green';
            
            badgeContainer.innerHTML = `<span class="badge ${badgeClass}">${calc.gesamt || calc.gesamt_punkte} P.</span>`;
        }
    }

    function mergeLocalCache(students) {
        return students.map(s => {
            const cachedInput = localStorage.getItem(`bjs_student_input_${s.id}`);
            if (cachedInput) {
                const inputs = JSON.parse(cachedInput);
                const calc = calculateLocalResults(s, inputs.ausdauer, inputs.sprint, inputs.sprung, inputs.wurf);
                return {
                    ...s,
                    ausdauer_leistung: inputs.ausdauer,
                    sprint_leistung: inputs.sprint,
                    sprung_leistung: inputs.sprung,
                    wurf_leistung: inputs.wurf,
                    gesamt_punkte: calc.gesamt,
                    urkunde: calc.urkunde,
                    note: calc.note
                };
            }
            return s;
        });
    }

    // ==========================================
    // 5. DATA INGESTION: CLASSES & STUDENTS
    // ==========================================
    function loadClasses() {
        if (classesLoaded) return;
        
        const renderClassesOptions = (classes) => {
            classSelect.innerHTML = '<option value="">-- Klasse wählen --</option>';
            classes.forEach(cls => {
                const opt = document.createElement('option');
                opt.value = cls;
                opt.textContent = `Klasse ${cls}`;
                classSelect.appendChild(opt);
            });
        };
        
        fetch('api.php?action=get_classes')
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                renderClassesOptions(res.classes);
                localStorage.setItem('bjs_classes_cache', JSON.stringify(res.classes));
                classesLoaded = true;
            }
        })
        .catch(err => {
            console.warn('Network failed to load classes, loading cache:', err);
            const cached = localStorage.getItem('bjs_classes_cache');
            if (cached) {
                renderClassesOptions(JSON.parse(cached));
                classesLoaded = true;
            } else {
                showToast('Klassen konnten nicht geladen werden (Offline & kein Cache).', 'error');
            }
        });
    }

    classSelect.addEventListener('change', () => {
        currentClass = classSelect.value;
        if (currentClass) {
            studentSearch.disabled = false;
            loadStudents();
        } else {
            studentSearch.disabled = true;
            studentSearch.value = '';
            studentListUl.innerHTML = '<li class="no-data">Bitte wählen Sie zuerst eine Klasse aus.</li>';
            if (btnPrintClass) btnPrintClass.disabled = true;
            closeInputForm();
        }
    });

    function loadStudents() {
        studentListUl.innerHTML = '<li class="no-data">Lade Schülerliste...</li>';
        
        const processStudentsData = (students) => {
            studentsList = mergeLocalCache(students);
            renderStudentList(studentsList);
            if (btnPrintClass) btnPrintClass.disabled = false;
        };
        
        fetch(`api.php?action=get_students&klasse=${currentClass}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                processStudentsData(res.students);
                localStorage.setItem(`bjs_students_cache_${currentClass}`, JSON.stringify(res.students));
            }
        })
        .catch(err => {
            console.warn('Network failed to load students, loading cache:', err);
            const cached = localStorage.getItem(`bjs_students_cache_${currentClass}`);
            if (cached) {
                processStudentsData(JSON.parse(cached));
            } else {
                studentListUl.innerHTML = '<li class="no-data">Verbindungsfehler (Offline & kein Cache).</li>';
                if (btnPrintClass) btnPrintClass.disabled = true;
            }
        });
    }

    function renderStudentList(list) {
        studentListUl.innerHTML = '';
        
        if (list.length === 0) {
            studentListUl.innerHTML = '<li class="no-data">Keine Schüler gefunden.</li>';
            return;
        }
        
        list.forEach(st => {
            const li = document.createElement('li');
            li.setAttribute('data-id', st.id);
            if (selectedStudent && selectedStudent.id === st.id) {
                li.classList.add('selected');
            }
            
            const hasParticipated = (st.ausdauer_leistung !== '' || st.sprint_leistung !== null || st.sprung_leistung !== null || st.wurf_leistung !== null);
            let badgeHtml = '<span class="badge badge-gray">Ausstehend</span>';
            if (hasParticipated) {
                let badgeClass = 'badge-orange';
                if (st.urkunde === 'Ehrenurkunde') badgeClass = 'badge-gold';
                if (st.urkunde === 'Siegerurkunde') badgeClass = 'badge-green';
                badgeHtml = `<span class="badge ${badgeClass}">${st.gesamt_punkte} P.</span>`;
            }
            
            li.innerHTML = `
                <div class="student-name-block">
                    <span class="fullname">${st.vorname} ${st.name}</span>
                    <span class="gender-age">${st.geschlecht} • Geb. ${st.geburtsjahr}</span>
                </div>
                <div class="badge-container">
                    ${badgeHtml}
                </div>
            `;
            
            li.addEventListener('click', () => selectStudent(st));
            studentListUl.appendChild(li);
        });
    }

    // Search and filtering
    studentSearch.addEventListener('input', () => {
        const query = studentSearch.value.toLowerCase().trim();
        if (query === '') {
            renderStudentList(studentsList);
        } else {
            const filtered = studentsList.filter(s => 
                s.name.toLowerCase().includes(query) || 
                s.vorname.toLowerCase().includes(query)
            );
            renderStudentList(filtered);
        }
    });

    function selectStudent(student) {
        // Save previous if any changes pending
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
            performSave();
        }
        
        selectedStudent = student;
        
        // Highlight in list
        document.querySelectorAll('#student-list-ul li').forEach(li => {
            li.classList.remove('selected');
            if (parseInt(li.getAttribute('data-id'), 10) === student.id) {
                li.classList.add('selected');
            }
        });
        
        // Setup Form
        formEmptyState.style.display = 'none';
        formActiveState.style.display = 'flex';
        
        const genderLabel = student.geschlecht === 'W' ? 'Mädchen' : 'Junge';
        formStudentName.textContent = `${student.vorname} ${student.name}`;
        formStudentMeta.textContent = `Klasse ${student.klasse} • Geburtsjahr: ${student.geburtsjahr} • ${genderLabel}`;
        
        // Avatar
        formAvatar.textContent = student.vorname.charAt(0) + student.name.charAt(0);
        formAvatar.className = 'profile-avatar';
        formAvatar.classList.add(student.geschlecht === 'W' ? 'avatar-girl' : 'avatar-boy');
        
        // Setup Sprint label dynamically based on class grade (Stufe)
        const stufe = student.klasse.length >= 2 ? student.klasse.charAt(1) : '';
        const sprintDist = (stufe === '5' || stufe === '6') ? '50m' : '75m';
        labelSprint.textContent = `Sprint (${sprintDist})`;
        
        // Set Inputs
        inputStudentId.value = student.id;
        inputSprint.value = student.sprint_leistung !== null ? student.sprint_leistung : '';
        inputSprung.value = student.sprung_leistung !== null ? student.sprung_leistung : '';
        inputWurf.value = student.wurf_leistung !== null ? student.wurf_leistung : '';
        inputAusdauer.value = student.ausdauer_leistung !== null ? student.ausdauer_leistung : '';
        
        saveStatus.className = 'auto-save-indicator'; // hide save status
        
        // Trigger initial calculation
        updateLiveCalculationUI();
        
        // Focus first field (sprint) for quick touch typing
        inputSprint.focus();
    }

    function closeInputForm() {
        selectedStudent = null;
        formEmptyState.style.display = 'flex';
        formActiveState.style.display = 'none';
    }

    // Form button handlers

    btnNextStudent.addEventListener('click', () => {
        if (!selectedStudent || studentsList.length === 0) return;
        
        // Save current changes first
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
            performSave();
        }
        
        // Find next student in list that has "Ausstehend" status, or just the next student overall
        const currentIndex = studentsList.findIndex(s => s.id === selectedStudent.id);
        if (currentIndex === -1) return;
        
        // Look for next student who is "Ausstehend"
        let nextIndex = -1;
        for (let i = 1; i <= studentsList.length; i++) {
            const idx = (currentIndex + i) % studentsList.length;
            const st = studentsList[idx];
            const hasParticipated = (st.ausdauer_leistung !== '' || st.sprint_leistung !== null || st.sprung_leistung !== null || st.wurf_leistung !== null);
            if (!hasParticipated) {
                nextIndex = idx;
                break;
            }
        }
        
        // Fallback: just next student in list
        if (nextIndex === -1) {
            nextIndex = (currentIndex + 1) % studentsList.length;
        }
        
        selectStudent(studentsList[nextIndex]);
        
        // Scroll the selected student row into view inside sidebar
        setTimeout(() => {
            const selectedLi = document.querySelector('#student-list-ul li.selected');
            if (selectedLi) {
                selectedLi.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }, 100);
    });

    // ==========================================
    // 6. DASHBOARD & CHART RENDERING
    // ==========================================
    function loadDashboard() {
        fetch('api.php?action=get_dashboard')
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                lastOverview = res.overview;
                lastClassStats = res.class_stats;
                renderOverviewKPIs(res.overview);
                renderClassStatsTable(res.class_stats);
                renderSchoolRecords(res.records);
                renderCharts(res.overview, res.class_stats);
            }
        })
        .catch(err => {
            console.error('Failed to load dashboard:', err);
            showToast('Dashboard konnte nicht geladen werden.', 'error');
        });
    }

    function renderOverviewKPIs(overview) {
        document.getElementById('kpi-participation-rate').textContent = `${overview.participation_rate}%`;
        document.getElementById('kpi-participation-summary').textContent = `${overview.participated_students} von ${overview.total_students} Schülern`;
        
        document.getElementById('kpi-ehrenurkunden').textContent = overview.ehrenurkunden;
        const ehrenPercent = overview.total_students > 0 ? Math.round((overview.ehrenurkunden / overview.total_students) * 100) : 0;
        document.getElementById('kpi-ehrenurkunden-percent').textContent = `${ehrenPercent}% aller Schüler`;
        
        document.getElementById('kpi-siegerurkunden').textContent = overview.siegerurkunden;
        const siegerPercent = overview.total_students > 0 ? Math.round((overview.siegerurkunden / overview.total_students) * 100) : 0;
        document.getElementById('kpi-siegerurkunden-percent').textContent = `${siegerPercent}% aller Schüler`;
        
        document.getElementById('kpi-teilnehmerurkunden').textContent = overview.teilnehmerurkunden;
        const teilnehmerPercent = overview.total_students > 0 ? Math.round((overview.teilnehmerurkunden / overview.total_students) * 100) : 0;
        document.getElementById('kpi-teilnehmerurkunden-percent').textContent = `${teilnehmerPercent}% aller Schüler`;
    }

    function renderClassStatsTable(stats) {
        const tbody = document.querySelector('#class-stats-table tbody');
        tbody.innerHTML = '';
        
        if (stats.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Keine Klassendaten vorhanden.</td></tr>';
            return;
        }
        
        stats.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>Klasse ${row.klasse}</strong></td>
                <td>${row.participated} / ${row.total}</td>
                <td>${row.ehren}</td>
                <td>${row.sieger}</td>
                <td>${row.teilnehmer}</td>
                <td><strong>${row.avg_punkte || 0} P.</strong></td>
            `;
            tbody.appendChild(tr);
        });
    }

    function renderSchoolRecords(records) {
        const container = document.getElementById('records-container');
        container.innerHTML = '';
        
        const recordEvents = [
            { key: 'sprint', name: 'Sprint', icon: '⚡' },
            { key: 'sprung', name: 'Weitsprung', icon: '🦘' },
            { key: 'wurf', name: 'Weitwurf', icon: '☄️' },
            { key: 'ausdauer', name: 'Ausdauer', icon: '🏃‍♂️' },
            { key: 'punkte', name: 'Gesamtpunkte', icon: '🏆' }
        ];
        
        recordEvents.forEach(evt => {
            ['W', 'M'].forEach(gender => {
                const rec = records[evt.key]?.[gender];
                if (rec) {
                    const genderSymbol = gender === 'W' ? '👩' : '👨';
                    const item = document.createElement('div');
                    item.className = 'record-item';
                    item.innerHTML = `
                        <div class="record-icon">${evt.icon}</div>
                        <div class="record-event">${evt.name} (${genderSymbol})</div>
                        <div class="record-val">${rec.leistung}</div>
                        <div class="record-holder">${rec.name}</div>
                        <div class="record-class">Klasse ${rec.klasse}</div>
                    `;
                    container.appendChild(item);
                }
            });
        });
        
        if (container.children.length === 0) {
            container.innerHTML = '<div class="record-loading">Noch keine Bestleistungen erfasst.</div>';
        }
    }

    function renderCharts(overview, classStats) {
        // Destroy existing charts to reload
        if (charts.urkunden) charts.urkunden.destroy();
        if (charts.klassen) charts.klassen.destroy();
        
        // Dynamically check theme
        const isLight = document.documentElement.classList.contains('light-theme');
        const textColor = isLight ? '#4B5563' : '#9CA3AF';
        const gridColor = isLight ? 'rgba(0, 0, 0, 0.06)' : 'rgba(255, 255, 255, 0.05)';
        const borderColor = isLight ? '#FFFFFF' : '#131A2D';
        const noneColor = isLight ? '#D1D5DB' : '#374151';
        
        // Chart 1: Urkunden distribution
        const ctxUrkunden = document.getElementById('chart-urkunden').getContext('2d');
        charts.urkunden = new Chart(ctxUrkunden, {
            type: 'doughnut',
            data: {
                labels: ['Ehrenurkunde', 'Siegerurkunde', 'Teilnehmerurkunde', 'Keine Teilnahme'],
                datasets: [{
                    data: [
                        overview.ehrenurkunden,
                        overview.siegerurkunden,
                        overview.teilnehmerurkunden,
                        overview.keine_teilnahme
                    ],
                    backgroundColor: ['#FFB300', '#10B981', '#F97316', noneColor],
                    borderColor: borderColor,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: textColor,
                            font: { family: 'Inter', size: 11 }
                        }
                    }
                }
            }
        });
        
        // Chart 2: Class averages
        const ctxKlassen = document.getElementById('chart-klassen-punkte').getContext('2d');
        charts.klassen = new Chart(ctxKlassen, {
            type: 'bar',
            data: {
                labels: classStats.map(c => `Klasse ${c.klasse}`),
                datasets: [{
                    label: 'Punkteschnitt',
                    data: classStats.map(c => c.avg_punkte || 0),
                    backgroundColor: 'rgba(59, 130, 246, 0.75)',
                    borderColor: '#3B82F6',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { family: 'Inter', size: 10 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor, font: { family: 'Inter', size: 10 } }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    // ==========================================
    // 7. ADMIN / DATABASE RESET
    // ==========================================
    btnResetDb.addEventListener('click', () => {
        const conf = confirm('Möchten Sie die Datenbank wirklich zurücksetzen und alle Ergebnisse auf die Ursprungswerte aus dem Google Sheet zurücksetzen?');
        if (!conf) return;
        
        btnResetDb.disabled = true;
        btnResetDb.textContent = 'Setze zurück...';
        
        fetch('api.php?action=reset_db', { method: 'POST' })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                showToast('Datenbank wurde erfolgreich importiert!');
                classesLoaded = false; // reload class dropdown
                selectedStudent = null;
                closeInputForm();
                if (activeView === 'dashboard') loadDashboard();
            } else {
                throw new Error(res.error || 'Server error');
            }
        })
        .catch(err => {
            console.error('Reset failed:', err);
            showToast('Fehler beim Zurücksetzen der Datenbank.', 'error');
        })
        .finally(() => {
            btnResetDb.disabled = false;
            btnResetDb.textContent = 'Datenbank zurücksetzen';
        });
    });

    // ==========================================
    // 8. PRINT / EXPORT FUNCTIONALITY
    // ==========================================
    const btnPrintClass = document.getElementById('btn-print-class');
    const btnPrintDashboard = document.getElementById('btn-print-dashboard');
    const btnPrintTop3 = document.getElementById('btn-print-top3');
    const printArea = document.getElementById('print-area');

    if (btnPrintClass) {
        btnPrintClass.addEventListener('click', () => {
            if (!currentClass || studentsList.length === 0) return;
            
            let html = `
                <div class="print-header">
                    <h2>Bundesjugendspiele 2026</h2>
                    <p>Auswertungsliste Klasse: <strong>${currentClass}</strong></p>
                </div>
                <table class="print-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Geb.</th>
                            <th>M/W</th>
                            <th>Sprint</th>
                            <th>Weitsprung</th>
                            <th>Weitwurf</th>
                            <th>Ausdauer</th>
                            <th>Punkte</th>
                            <th>Urkunde</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            studentsList.forEach(st => {
                const sprintVal = st.sprint_leistung !== null ? `${st.sprint_leistung}s` : '-';
                const sprungVal = st.sprung_leistung !== null ? `${st.sprung_leistung}m` : '-';
                const wurfVal = st.wurf_leistung !== null ? `${st.wurf_leistung}m` : '-';
                const ausdauerVal = st.ausdauer_leistung !== null && st.ausdauer_leistung !== '' ? st.ausdauer_leistung : '-';
                
                // Determine actual current values from local state or sync queue
                const cachedInput = localStorage.getItem(`bjs_student_input_${st.id}`);
                let sprint = sprintVal;
                let sprung = sprungVal;
                let wurf = wurfVal;
                let ausdauer = ausdauerVal;
                let gesamt = st.gesamt_punkte !== null && st.gesamt_punkte > 0 ? `${st.gesamt_punkte} P.` : '-';
                let urkunde = st.urkunde && st.gesamt_punkte > 0 ? st.urkunde : '-';
                let note = st.note && st.gesamt_punkte > 0 ? st.note : '-';

                if (cachedInput) {
                    const inputs = JSON.parse(cachedInput);
                    sprint = inputs.sprint !== '' ? `${inputs.sprint}s` : '-';
                    sprung = inputs.sprung !== '' ? `${inputs.sprung}m` : '-';
                    wurf = inputs.wurf !== '' ? `${inputs.wurf}m` : '-';
                    ausdauer = inputs.ausdauer !== '' ? inputs.ausdauer : '-';
                }
                
                html += `
                    <tr>
                        <td><strong>${st.name}, ${st.vorname}</strong></td>
                        <td>${st.geburtsjahr}</td>
                        <td>${st.geschlecht}</td>
                        <td>${sprint}</td>
                        <td>${sprung}</td>
                        <td>${wurf}</td>
                        <td>${ausdauer}</td>
                        <td><strong>${gesamt}</strong></td>
                        <td>${urkunde}</td>
                        <td>${note}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            printArea.innerHTML = html;
            const originalTitle = document.title;
            document.title = `BJS_2026_Klassenliste_${currentClass}`;
            document.body.classList.add('print-active-area');
            window.print();
            document.body.classList.remove('print-active-area');
            document.title = originalTitle;
        });
    }

    if (btnPrintDashboard) {
        btnPrintDashboard.addEventListener('click', () => {
            const originalTitle = document.title;
            document.title = "BJS_2026_Dashboard";
            document.body.classList.add('print-dashboard-layout');
            window.print();
            document.body.classList.remove('print-dashboard-layout');
            document.title = originalTitle;
        });
    }

    if (btnPrintTop3) {
        btnPrintTop3.addEventListener('click', () => {
            btnPrintTop3.disabled = true;
            btnPrintTop3.textContent = 'Lade Daten...';
            
            fetch('api.php?action=get_top3')
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    let html = `
                        <div class="print-header">
                            <h2>Bundesjugendspiele 2026</h2>
                            <p><strong>Die 3 Bestleistungen je Disziplin (Schulrekorde)</strong></p>
                        </div>
                    `;
                    
                    const events = [
                        { key: 'punkte', name: '🏆 Mehrkampf (Gesamtpunkte)', desc: 'Beste Dreikampf-Ergebnisse (Mädchen / Jungen)' },
                        { key: 'sprint', name: '⚡ Sprint', desc: 'Laufzeiten in Sekunden' },
                        { key: 'sprung', name: '🦘 Weitsprung', desc: 'Sprungweite in Metern' },
                        { key: 'wurf', name: '☄️ Weitwurf (Schlagball)', desc: 'Wurfweite in Metern' },
                        { key: 'ausdauer', name: '🏃‍♂️ Ausdauerlauf', desc: 'Laufzeit in Minuten:Sekunden' }
                    ];
                    
                    events.forEach(evt => {
                        html += `
                            <div class="print-highscore-section">
                                <div class="print-highscore-title">${evt.name} <span style="font-size:9pt; font-weight:normal; text-transform:none; margin-left:10px;">${evt.desc}</span></div>
                                <div class="print-highscore-grid">
                                    <div>
                                        <h4 style="margin-bottom:5px; font-size:10pt;">👩 Mädchen</h4>
                                        <table class="print-table" style="margin: 0; width:100%;">
                                            <thead>
                                                <tr>
                                                    <th style="width: 15%;">Rang</th>
                                                    <th>Name</th>
                                                    <th style="width: 20%;">Klasse</th>
                                                    <th style="width: 25%;">Leistung</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                        `;
                        
                        const girls = res[evt.key]?.W || [];
                        for (let i = 0; i < 3; i++) {
                            const row = girls[i];
                            html += `
                                <tr>
                                    <td><strong>${i + 1}</strong></td>
                                    <td>${row ? row.name : '-'}</td>
                                    <td>${row ? row.klasse : '-'}</td>
                                    <td><strong>${row ? row.leistung : '-'}</strong></td>
                                </tr>
                            `;
                        }
                        
                        html += `
                                            </tbody>
                                        </table>
                                    </div>
                                    <div>
                                        <h4 style="margin-bottom:5px; font-size:10pt;">👨 Jungen</h4>
                                        <table class="print-table" style="margin: 0; width:100%;">
                                            <thead>
                                                <tr>
                                                    <th style="width: 15%;">Rang</th>
                                                    <th>Name</th>
                                                    <th style="width: 20%;">Klasse</th>
                                                    <th style="width: 25%;">Leistung</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                        `;
                        
                        const boys = res[evt.key]?.M || [];
                        for (let i = 0; i < 3; i++) {
                            const row = boys[i];
                            html += `
                                <tr>
                                    <td><strong>${i + 1}</strong></td>
                                    <td>${row ? row.name : '-'}</td>
                                    <td>${row ? row.klasse : '-'}</td>
                                    <td><strong>${row ? row.leistung : '-'}</strong></td>
                                </tr>
                            `;
                        }
                        
                        html += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    printArea.innerHTML = html;
                    const originalTitle = document.title;
                    document.title = "BJS_2026_Bestenliste_Top3";
                    document.body.classList.add('print-active-area');
                    window.print();
                    document.body.classList.remove('print-active-area');
                    document.title = originalTitle;
                }
            })
            .catch(err => {
                console.error('Failed to load top 3 list:', err);
                showToast('Bestenliste konnte nicht geladen werden.', 'error');
            })
            .finally(() => {
                btnPrintTop3.disabled = false;
                btnPrintTop3.textContent = '🏆 Bestenliste drucken (Top 3)';
            });
        });
    }
});
