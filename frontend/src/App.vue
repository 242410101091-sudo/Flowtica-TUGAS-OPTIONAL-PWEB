<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

// STATE
const todos = ref([])
const page = ref('dashboard')
const filter = ref('all')
const searchQuery = ref('')
const showModal = ref(false)
const editingTodo = ref(null)
const toasts = ref([])
const stats = ref({ total: 0, completed: 0, inprogress: 0, overdue: 0, productivity: 0 })
const activityView = ref('weekly')
const isLoading = ref(false) // FIX: tambah loading state

// FOCUS MODE STATE
const focusMinutes = ref(25)
const breakMinutes = ref(5)
const focusSeconds = ref(25 * 60)
const focusTotalSeconds = ref(25 * 60)
const focusMode = ref('focus')
const focusRunning = ref(false)
const focusSessions = ref(0)
// FIX: deklarasi interval di dalam scope yang bisa di-clear
let focusInterval = null

const form = ref({
  title: '', notes: '', status: 'todo',
  priority: 'medium', category: 'General',
  progress: 0, deadline: ''
})
// FIX: track apakah form sudah dimodifikasi
const formDirty = ref(false)

const kanbanCols = [
  { key: 'todo', label: '📋 To Do' },
  { key: 'inprogress', label: '⚡ In Progress' },
  { key: 'done', label: '✅ Done' },
]

const priorities = ['critical', 'high', 'medium', 'low']

// COMPUTED
const today = computed(() => new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }))
const pageTitle = computed(() => ({ dashboard: 'Dashboard', tasks: 'Tasks', kanban: 'Kanban Board', analytics: 'Analytics', focus: 'Focus Mode' })[page.value] || 'Flowtica')
const pendingCount = computed(() => todos.value.filter(t => !t.is_completed).length)

const filteredTodos = computed(() => {
  let list = todos.value
  if (filter.value === 'pending') list = list.filter(t => !t.is_completed && t.status !== 'done')
  else if (filter.value === 'completed') list = list.filter(t => t.is_completed)
  else if (filter.value === 'overdue') list = list.filter(t => !t.is_completed && t.deadline && new Date(t.deadline) < new Date())
  else if (filter.value === 'critical') list = list.filter(t => t.priority === 'critical')
  else if (filter.value === 'high') list = list.filter(t => t.priority === 'high')
  else if (filter.value === 'medium') list = list.filter(t => t.priority === 'medium')
  else if (filter.value === 'low') list = list.filter(t => t.priority === 'low')
  if (searchQuery.value) list = list.filter(t => t.title.toLowerCase().includes(searchQuery.value.toLowerCase()))
  return list
})

const activityData = computed(() => {
  const now = new Date()
  const labels = []
  const data = []
  if (activityView.value === 'daily') {
    for (let i = 6; i >= 0; i--) {
      const d = new Date(now)
      d.setDate(d.getDate() - i)
      labels.push(d.toLocaleDateString('en-US', { weekday: 'short' }))
      data.push(todos.value.filter(t => t.created_at && new Date(t.created_at).toDateString() === d.toDateString()).length)
    }
  } else if (activityView.value === 'weekly') {
    for (let i = 3; i >= 0; i--) {
      const weekStart = new Date(now)
      weekStart.setDate(weekStart.getDate() - (i * 7))
      const weekEnd = new Date(weekStart)
      weekEnd.setDate(weekEnd.getDate() + 7)
      labels.push(`Week ${4 - i}`)
      data.push(todos.value.filter(t => {
        if (!t.created_at) return false
        const td = new Date(t.created_at)
        return td >= weekStart && td < weekEnd
      }).length)
    }
  } else {
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
    for (let i = 0; i < 12; i++) {
      labels.push(months[i])
      data.push(todos.value.filter(t => {
        if (!t.created_at) return false
        const td = new Date(t.created_at)
        return td.getFullYear() === now.getFullYear() && td.getMonth() === i
      }).length)
    }
  }
  return { labels, data }
})

const maxActivity = computed(() => Math.max(...activityData.value.data, 1))

const completedToday = computed(() => {
  const todayStr = new Date().toDateString()
  return todos.value.filter(t => t.is_completed && new Date(t.updated_at).toDateString() === todayStr).length
})

const avgProgress = computed(() => {
  if (todos.value.length === 0) return 0
  return Math.round(todos.value.reduce((sum, t) => sum + (t.progress || 0), 0) / todos.value.length)
})

const tasksByCategory = computed(() => {
  const cats = {}
  todos.value.forEach(t => { cats[t.category] = (cats[t.category] || 0) + 1 })
  return Object.entries(cats).map(([name, count]) => ({ name, count })).sort((a, b) => b.count - a.count)
})

// FOCUS COMPUTED
const focusTimerDisplay = computed(() => {
  const m = Math.floor(focusSeconds.value / 60)
  const s = focusSeconds.value % 60
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})

const focusRingPct = computed(() => focusSeconds.value / focusTotalSeconds.value)

// FUNCTIONS
const fetchTodos = async () => {
  try {
    const res = await axios.get(`${API_URL}/todos`)
    todos.value = res.data.data
  } catch (e) {
    console.error('[fetchTodos]', e) // FIX: log detail error
    showToast('error', 'Gagal memuat tasks')
  }
}

const fetchStats = async () => {
  try {
    const res = await axios.get(`${API_URL}/todos-stats`)
    stats.value = res.data.data
  } catch (e) {
    console.error('[fetchStats]', e) // FIX: log detail error
  }
}

// FIX: gunakan Promise.all untuk fetch paralel
const refreshData = async () => {
  await Promise.all([fetchTodos(), fetchStats()])
}

const openModal = (todo = null) => {
  editingTodo.value = todo
  form.value = todo ? {
    title: todo.title, notes: todo.notes || '', status: todo.status,
    priority: todo.priority, category: todo.category,
    progress: todo.progress, deadline: todo.deadline ? todo.deadline.slice(0, 16) : ''
  } : { title: '', notes: '', status: 'todo', priority: 'medium', category: 'General', progress: 0, deadline: '' }
  formDirty.value = false // FIX: reset dirty state
  showModal.value = true
}

// FIX: konfirmasi jika form sudah diisi dan belum disimpan
const closeModal = () => {
  if (formDirty.value && !confirm('Perubahan belum disimpan. Tutup modal?')) return
  showModal.value = false
  editingTodo.value = null
  formDirty.value = false
}

const submitForm = async () => {
  if (!form.value.title.trim()) { showToast('error', 'Judul tidak boleh kosong'); return }
  try {
    if (editingTodo.value) {
      await axios.put(`${API_URL}/todos/${editingTodo.value.id}`, form.value)
      showToast('success', 'Task berhasil diperbarui!')
    } else {
      await axios.post(`${API_URL}/todos`, form.value)
      showToast('success', 'Task berhasil dibuat!')
    }
    formDirty.value = false
    closeModal()
    await refreshData() // FIX: pakai Promise.all
  } catch (e) {
    console.error('[submitForm]', e) // FIX: log detail error
    showToast('error', 'Terjadi kesalahan. Coba lagi.')
  }
}

const toggleTodo = async (todo) => {
  try {
    await axios.put(`${API_URL}/todos/${todo.id}`, {
      is_completed: !todo.is_completed,
      status: todo.is_completed ? 'todo' : 'done',
      progress: todo.is_completed ? 0 : 100
    })
    await refreshData() // FIX: pakai Promise.all
  } catch (e) {
    console.error('[toggleTodo]', e)
    showToast('error', 'Gagal mengupdate task')
  }
}

const deleteTodo = async (id) => {
  // FIX: teks confirm konsisten dalam Bahasa Indonesia
  if (!confirm('Hapus task ini? Tindakan ini tidak dapat dibatalkan.')) return
  try {
    await axios.delete(`${API_URL}/todos/${id}`)
    showToast('success', 'Task berhasil dihapus!')
    await refreshData() // FIX: pakai Promise.all
  } catch (e) {
    console.error('[deleteTodo]', e)
    showToast('error', 'Gagal menghapus task')
  }
}

const moveTask = async (todo, direction, currentStatus) => {
  const order = ['todo', 'inprogress', 'done']
  const idx = order.indexOf(currentStatus)
  const newStatus = direction === 'next' ? order[idx + 1] : order[idx - 1]
  if (!newStatus) return
  try {
    await axios.put(`${API_URL}/todos/${todo.id}`, {
      status: newStatus,
      is_completed: newStatus === 'done',
      progress: newStatus === 'done' ? 100 : newStatus === 'inprogress' ? 50 : 0
    })
    await refreshData() // FIX: pakai Promise.all
  } catch (e) {
    console.error('[moveTask]', e)
    showToast('error', 'Gagal memindahkan task')
  }
}

const todosByStatus = (status) => todos.value.filter(t => t.status === status)
const todosByPriority = (priority) => todos.value.filter(t => t.priority === priority).length
const priorityPct = (priority) => todos.value.length === 0 ? 0 : Math.round((todosByPriority(priority) / todos.value.length) * 100)
const formatDate = (date) => date ? new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : ''

// FOCUS FUNCTIONS
// FIX: helper untuk clear interval dengan aman
const clearFocusInterval = () => {
  if (focusInterval) {
    clearInterval(focusInterval)
    focusInterval = null
  }
}

const startFocus = () => {
  if (focusRunning.value) {
    clearFocusInterval()
    focusRunning.value = false
    return
  }
  focusRunning.value = true
  focusInterval = setInterval(() => {
    focusSeconds.value--
    if (focusSeconds.value <= 0) {
      clearFocusInterval()
      focusRunning.value = false
      if (focusMode.value === 'focus') {
        focusSessions.value++
        showToast('success', '🎉 Sesi fokus selesai! Istirahat dulu.')
        focusMode.value = 'break'
        focusSeconds.value = breakMinutes.value * 60
        focusTotalSeconds.value = breakMinutes.value * 60
      } else {
        showToast('success', 'Istirahat selesai! Siap fokus lagi?')
        focusMode.value = 'focus'
        focusSeconds.value = focusMinutes.value * 60
        focusTotalSeconds.value = focusMinutes.value * 60
      }
    }
  }, 1000)
}

const resetFocus = () => {
  clearFocusInterval() // FIX: gunakan helper
  focusRunning.value = false
  focusMode.value = 'focus'
  focusSeconds.value = focusMinutes.value * 60
  focusTotalSeconds.value = focusMinutes.value * 60
}

const skipFocus = () => {
  clearFocusInterval() // FIX: gunakan helper
  focusRunning.value = false
  if (focusMode.value === 'focus') {
    focusSessions.value++
    showToast('success', 'Diskip! Nikmati istirahatmu.')
    focusMode.value = 'break'
    focusSeconds.value = breakMinutes.value * 60
    focusTotalSeconds.value = breakMinutes.value * 60
  } else {
    showToast('success', 'Istirahat diskip! Kembali fokus.')
    focusMode.value = 'focus'
    focusSeconds.value = focusMinutes.value * 60
    focusTotalSeconds.value = focusMinutes.value * 60
  }
}

// FIX: update both focus and break seconds correctly
const applyFocusSettings = () => {
  if (focusRunning.value) return
  if (focusMode.value === 'focus') {
    focusSeconds.value = focusMinutes.value * 60
    focusTotalSeconds.value = focusMinutes.value * 60
  } else {
    focusSeconds.value = breakMinutes.value * 60
    focusTotalSeconds.value = breakMinutes.value * 60
  }
}

const showToast = (type, message) => {
  const id = Date.now()
  toasts.value.push({ id, type, message })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3000)
}

onMounted(async () => {
  isLoading.value = true
  await refreshData()
  isLoading.value = false
})

// FIX: cleanup interval saat komponen di-unmount (mencegah memory leak)
onUnmounted(() => {
  clearFocusInterval()
})
</script>

<template>
  <div class="app-wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-logo">
        <div class="logo-mark">Flow<span>tica</span></div>
        <div class="logo-tagline">Plan. Prioritize. Progress.</div>
      </div>
      <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <div class="nav-item" :class="{ active: page === 'dashboard' }" @click="page = 'dashboard'">
          <span class="nav-icon">⊞</span> Dashboard
        </div>
        <div class="nav-item" :class="{ active: page === 'tasks' }" @click="page = 'tasks'">
          <span class="nav-icon">✓</span> Tasks
          <span class="nav-badge" v-if="pendingCount > 0">{{ pendingCount }}</span>
        </div>
        <div class="nav-item" :class="{ active: page === 'kanban' }" @click="page = 'kanban'">
          <span class="nav-icon">⊟</span> Kanban
        </div>
        <div class="nav-section-label">Insights</div>
        <div class="nav-item" :class="{ active: page === 'analytics' }" @click="page = 'analytics'">
          <span class="nav-icon">◎</span> Analytics
        </div>
        <div class="nav-section-label">Tools</div>
        <div class="nav-item" :class="{ active: page === 'focus' }" @click="page = 'focus'">
          <span class="nav-icon">🎯</span> Focus Mode
        </div>
      </nav>
      <div class="sidebar-footer">
        <div class="user-profile">
          <div class="user-avatar">F</div>
          <div>
            <div class="user-name">Flowtica User</div>
            <div class="user-role">Personal</div>
          </div>
        </div>
      </div>
    </aside>

    <!-- MAIN -->
    <div class="main-content">
      <div class="topbar">
        <div>
          <div class="topbar-title">{{ pageTitle }}</div>
          <div class="topbar-subtitle">{{ today }}</div>
        </div>
        <div class="search-bar">
          <span>🔍</span>
          <input v-model="searchQuery" placeholder="Search tasks..." />
        </div>
        <div class="topbar-actions">
          <!-- FIX: tampilkan loading indicator di topbar -->
          <span v-if="isLoading" class="loading-dot">Memuat...</span>
          <button class="btn btn-primary" @click="openModal()">+ New Task</button>
        </div>
      </div>

      <div class="page-content">

        <!-- ═══ DASHBOARD ═══ -->
        <div v-if="page === 'dashboard'">
          <div class="stats-grid">
            <div class="stat-card c-purple">
              <div class="stat-icon">✦</div>
              <div class="stat-value">{{ stats.total }}</div>
              <div class="stat-label">Total Tasks</div>
            </div>
            <div class="stat-card c-green">
              <div class="stat-icon">✓</div>
              <div class="stat-value">{{ stats.completed }}</div>
              <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card c-blue">
              <div class="stat-icon">◷</div>
              <div class="stat-value">{{ stats.inprogress }}</div>
              <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card c-red">
              <div class="stat-icon">⚠</div>
              <div class="stat-value">{{ stats.overdue }}</div>
              <div class="stat-label">Overdue</div>
            </div>
          </div>

          <div class="dashboard-grid">

            <!-- Activity Chart -->
            <div class="card card-wide">
              <div class="card-header">
                <div class="section-title">Task Activity</div>
                <div class="tab-group">
                  <button class="tab-btn" :class="{ active: activityView === 'daily' }" @click="activityView = 'daily'">Daily</button>
                  <button class="tab-btn" :class="{ active: activityView === 'weekly' }" @click="activityView = 'weekly'">Weekly</button>
                  <button class="tab-btn" :class="{ active: activityView === 'yearly' }" @click="activityView = 'yearly'">Yearly</button>
                </div>
              </div>
              <div class="bar-chart">
                <div v-for="(val, i) in activityData.data" :key="i" class="bar-col">
                  <div class="bar-fill" :style="{ height: (val / maxActivity * 100) + '%' }"></div>
                  <div class="bar-label">{{ activityData.labels[i] }}</div>
                </div>
              </div>
            </div>

            <!-- Productivity Score -->
            <div class="card">
              <div class="section-title" style="margin-bottom:20px">Productivity Score</div>
              <div class="score-ring-wrap">
                <svg viewBox="0 0 120 120" class="score-ring">
                  <circle cx="60" cy="60" r="50" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="10"/>
                  <circle cx="60" cy="60" r="50" fill="none" stroke="url(#grad)" stroke-width="10"
                    stroke-dasharray="314.16"
                    :stroke-dashoffset="314.16 * (1 - stats.productivity / 100)"
                    stroke-linecap="round" transform="rotate(-90 60 60)"/>
                  <defs>
                    <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="0%">
                      <stop offset="0%" stop-color="#8b5cf6"/>
                      <stop offset="100%" stop-color="#3b82f6"/>
                    </linearGradient>
                  </defs>
                </svg>
                <div class="score-value">{{ stats.productivity }}%</div>
              </div>
              <div class="score-stats">
                <div class="score-stat">
                  <div class="score-stat-val">{{ completedToday }}</div>
                  <div class="score-stat-label">Done Today</div>
                </div>
                <div class="score-stat">
                  <div class="score-stat-val">{{ avgProgress }}%</div>
                  <div class="score-stat-label">Avg Progress</div>
                </div>
                <div class="score-stat">
                  <div class="score-stat-val">{{ todosByPriority('critical') }}</div>
                  <div class="score-stat-label">Critical</div>
                </div>
              </div>
            </div>

            <!-- Priority Distribution -->
            <div class="card">
              <div class="section-title" style="margin-bottom:20px">Priority Distribution</div>
              <div v-for="p in priorities" :key="p" class="priority-row">
                <span class="badge" :class="'badge-' + p">{{ p }}</span>
                <div class="priority-bar-wrap">
                  <div class="priority-bar-fill" :class="'bar-' + p" :style="{ width: priorityPct(p) + '%' }"></div>
                </div>
                <span class="priority-count">{{ todosByPriority(p) }}</span>
              </div>
            </div>

            <!-- Recent Tasks -->
            <div class="card card-wide">
              <div class="section-title" style="margin-bottom:16px">Recent Tasks</div>
              <div v-if="todos.length === 0" class="empty-state">
                <span v-if="isLoading">Memuat tasks...</span>
                <span v-else>Belum ada task. Buat task pertamamu!</span>
              </div>
              <div v-for="todo in todos.slice(0, 5)" :key="todo.id" class="task-row">
                <div class="task-row-left">
                  <input type="checkbox" :checked="todo.is_completed" @change="toggleTodo(todo)" />
                  <span :class="{ 'done-text': todo.is_completed }">{{ todo.title }}</span>
                  <span class="badge" :class="'badge-' + todo.priority">{{ todo.priority }}</span>
                </div>
                <span class="badge badge-status">{{ todo.status }}</span>
              </div>
            </div>

          </div>
        </div>

        <!-- ═══ TASKS ═══ -->
        <div v-if="page === 'tasks'">
          <div class="section-header">
            <div>
              <div class="section-title">All Tasks</div>
              <div class="section-subtitle">{{ filteredTodos.length }} tasks ditemukan</div>
            </div>
            <button class="btn btn-primary" @click="openModal()">+ New Task</button>
          </div>
          <div class="filter-bar">
            <button class="btn btn-sm" :class="filter === 'all' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'all'">All</button>
            <button class="btn btn-sm" :class="filter === 'pending' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'pending'">Pending</button>
            <button class="btn btn-sm" :class="filter === 'completed' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'completed'">Completed</button>
            <button class="btn btn-sm" :class="filter === 'overdue' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'overdue'">⚠ Overdue</button>
            <button class="btn btn-sm" :class="filter === 'critical' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'critical'">🔴 Critical</button>
            <button class="btn btn-sm" :class="filter === 'high' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'high'">🟠 High</button>
            <button class="btn btn-sm" :class="filter === 'medium' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'medium'">🟡 Medium</button>
            <button class="btn btn-sm" :class="filter === 'low' ? 'btn-primary' : 'btn-ghost'" @click="filter = 'low'">🔵 Low</button>
          </div>
          <div v-if="filteredTodos.length === 0" class="empty-state">
            <span v-if="isLoading">Memuat tasks...</span>
            <span v-else>Tidak ada task ditemukan.</span>
          </div>
          <div v-for="todo in filteredTodos" :key="todo.id" class="task-card">
            <div class="task-card-header">
              <div class="task-card-left">
                <input type="checkbox" :checked="todo.is_completed" @change="toggleTodo(todo)" />
                <span class="task-title" :class="{ 'done-text': todo.is_completed }">{{ todo.title }}</span>
              </div>
              <div class="task-card-actions">
                <button class="btn btn-sm btn-ghost" @click="openModal(todo)">Edit</button>
                <button class="btn btn-sm btn-danger" @click="deleteTodo(todo.id)">Hapus</button>
              </div>
            </div>
            <div class="task-meta">
              <span class="badge" :class="'badge-' + todo.priority">{{ todo.priority }}</span>
              <span class="badge badge-cat">{{ todo.category }}</span>
              <span class="badge badge-status">{{ todo.status }}</span>
              <span v-if="todo.deadline" class="task-deadline">📅 {{ formatDate(todo.deadline) }}</span>
            </div>
            <div class="progress-bar-wrap" v-if="todo.progress > 0">
              <div class="progress-bar-fill" :style="{ width: todo.progress + '%' }"></div>
            </div>
          </div>
        </div>

        <!-- ═══ KANBAN ═══ -->
        <div v-if="page === 'kanban'">
          <div class="section-header">
            <div>
              <div class="section-title">Kanban Board</div>
              <div class="section-subtitle">Pindahkan task antar kolom</div>
            </div>
            <button class="btn btn-primary" @click="openModal()">+ New Task</button>
          </div>
          <div class="kanban-board">
            <div class="kanban-col" v-for="col in kanbanCols" :key="col.key">
              <div class="kanban-col-header">
                <span>{{ col.label }}</span>
                <span class="nav-badge">{{ todosByStatus(col.key).length }}</span>
              </div>
              <div class="kanban-body">
                <div v-if="todosByStatus(col.key).length === 0" class="kanban-empty">Tidak ada task</div>
                <div v-for="todo in todosByStatus(col.key)" :key="todo.id" class="kanban-card">
                  <div class="kanban-card-title">{{ todo.title }}</div>
                  <div class="task-meta">
                    <span class="badge" :class="'badge-' + todo.priority">{{ todo.priority }}</span>
                    <span class="badge badge-cat">{{ todo.category }}</span>
                  </div>
                  <div class="kanban-card-actions">
                    <button v-if="col.key !== 'todo'" class="btn btn-sm btn-ghost" @click="moveTask(todo, 'prev', col.key)">← Back</button>
                    <button v-if="col.key !== 'done'" class="btn btn-sm btn-primary" @click="moveTask(todo, 'next', col.key)">Next →</button>
                    <button class="btn btn-sm btn-danger" @click="deleteTodo(todo.id)">✕</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══ ANALYTICS ═══ -->
        <div v-if="page === 'analytics'">
          <div class="section-header">
            <div>
              <div class="section-title">Analytics</div>
              <div class="section-subtitle">Deep dive into your productivity</div>
            </div>
          </div>
          <div class="stats-grid">
            <div class="stat-card c-purple">
              <div class="stat-icon">%</div>
              <div class="stat-value">{{ stats.productivity }}%</div>
              <div class="stat-label">Completion Rate</div>
            </div>
            <div class="stat-card c-blue">
              <div class="stat-icon">∅</div>
              <div class="stat-value">{{ avgProgress }}%</div>
              <div class="stat-label">Avg Progress</div>
            </div>
            <div class="stat-card c-green">
              <div class="stat-icon">✦</div>
              <div class="stat-value">{{ completedToday }}</div>
              <div class="stat-label">Done Today</div>
            </div>
            <div class="stat-card c-red">
              <div class="stat-icon">!</div>
              <div class="stat-value">{{ todosByPriority('critical') }}</div>
              <div class="stat-label">Critical Tasks</div>
            </div>
          </div>
          <div class="dashboard-grid">
            <div class="card">
              <div class="section-title" style="margin-bottom:16px">Task by Priority</div>
              <div v-for="p in priorities" :key="p" class="priority-row">
                <span class="badge" :class="'badge-' + p">{{ p }}</span>
                <div class="priority-bar-wrap">
                  <div class="priority-bar-fill" :class="'bar-' + p" :style="{ width: priorityPct(p) + '%' }"></div>
                </div>
                <span class="priority-count">{{ todosByPriority(p) }}</span>
              </div>
            </div>
            <div class="card">
              <div class="section-title" style="margin-bottom:16px">Task by Category</div>
              <div v-if="tasksByCategory.length === 0" class="empty-state">Belum ada data.</div>
              <div v-for="cat in tasksByCategory" :key="cat.name" class="priority-row">
                <span class="cat-label">{{ cat.name }}</span>
                <div class="priority-bar-wrap">
                  <div class="priority-bar-fill bar-medium" :style="{ width: (stats.total ? (cat.count / stats.total * 100) : 0) + '%' }"></div>
                </div>
                <span class="priority-count">{{ cat.count }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══ FOCUS MODE ═══ -->
        <div v-if="page === 'focus'">
          <div class="focus-layout">
            <div class="focus-main card">
              <div class="focus-mode-label" :class="focusMode === 'focus' ? 'label-focus' : 'label-break'">
                {{ focusMode === 'focus' ? '🎯 Focus Session' : '🌿 Break Time' }}
              </div>
              <div class="focus-ring-wrap">
                <svg viewBox="0 0 200 200" class="focus-ring-svg">
                  <circle cx="100" cy="100" r="85" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="12"/>
                  <circle cx="100" cy="100" r="85" fill="none"
                    :stroke="focusMode === 'focus' ? 'url(#focusGrad)' : '#10b981'"
                    stroke-width="12"
                    stroke-dasharray="534.07"
                    :stroke-dashoffset="534.07 * (1 - focusRingPct)"
                    stroke-linecap="round"
                    transform="rotate(-90 100 100)"/>
                  <defs>
                    <linearGradient id="focusGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                      <stop offset="0%" stop-color="#8b5cf6"/>
                      <stop offset="100%" stop-color="#3b82f6"/>
                    </linearGradient>
                  </defs>
                </svg>
                <div class="focus-timer">{{ focusTimerDisplay }}</div>
              </div>
              <div class="focus-btns">
                <button class="btn btn-ghost" @click="resetFocus">↺ Reset</button>
                <button class="btn btn-primary focus-start-btn" @click="startFocus">
                  {{ focusRunning ? '⏸ Pause' : '▶ Start' }}
                </button>
                <button class="btn btn-ghost" @click="skipFocus">⏭ Skip</button>
              </div>
              <div class="focus-sessions">Sesi selesai: <strong>{{ focusSessions }}</strong></div>
            </div>

            <div class="focus-settings card">
              <div class="section-title" style="margin-bottom:20px">⚙ Timer Settings</div>
              <div class="form-group">
                <label>Durasi Fokus: {{ focusMinutes }} menit</label>
                <input type="range" v-model.number="focusMinutes" min="1" max="60" @change="applyFocusSettings" :disabled="focusRunning" />
                <div class="range-labels"><span>1 min</span><span>60 min</span></div>
              </div>
              <div class="form-group">
                <label>Durasi Istirahat: {{ breakMinutes }} menit</label>
                <input type="range" v-model.number="breakMinutes" min="1" max="30" @change="applyFocusSettings" :disabled="focusRunning" />
                <div class="range-labels"><span>1 min</span><span>30 min</span></div>
              </div>
              <button class="btn btn-primary" style="width:100%; justify-content:center; margin-top:8px" @click="applyFocusSettings" :disabled="focusRunning">
                Terapkan Pengaturan
              </button>
              <div v-if="focusRunning" class="focus-warning">⚠ Hentikan timer sebelum mengubah pengaturan</div>
              <div style="margin-top:24px; border-top:1px solid var(--border); padding-top:20px">
                <div class="section-title" style="margin-bottom:12px; font-size:13px">Ringkasan Sesi</div>
                <div class="score-stats">
                  <div class="score-stat">
                    <div class="score-stat-val">{{ focusSessions }}</div>
                    <div class="score-stat-label">Sesi</div>
                  </div>
                  <div class="score-stat">
                    <div class="score-stat-val">{{ focusSessions * focusMinutes }}</div>
                    <div class="score-stat-label">Menit Fokus</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- MODAL -->
    <div class="modal-overlay" v-if="showModal" @click.self="closeModal">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">{{ editingTodo ? 'Edit Task' : 'Task Baru' }}</div>
          <button class="modal-close" @click="closeModal">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Judul *</label>
            <!-- FIX: track perubahan form dengan @input -->
            <input v-model="form.title" placeholder="Judul task..." @input="formDirty = true" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Prioritas</label>
              <select v-model="form.priority" @change="formDirty = true">
                <option value="low">🔵 Low</option>
                <option value="medium">🟡 Medium</option>
                <option value="high">🟠 High</option>
                <option value="critical">🔴 Critical</option>
              </select>
            </div>
            <div class="form-group">
              <label>Status</label>
              <select v-model="form.status" @change="formDirty = true">
                <option value="todo">To Do</option>
                <option value="inprogress">In Progress</option>
                <option value="done">Done</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Kategori</label>
              <input v-model="form.category" placeholder="Work, Personal, dll..." @input="formDirty = true" />
            </div>
            <div class="form-group">
              <label>Deadline</label>
              <input type="datetime-local" v-model="form.deadline" @change="formDirty = true" />
            </div>
          </div>
          <div class="form-group">
            <label>Progress ({{ form.progress }}%)</label>
            <input type="range" v-model.number="form.progress" min="0" max="100" @input="formDirty = true" />
          </div>
          <div class="form-group">
            <label>Catatan</label>
            <textarea v-model="form.notes" placeholder="Catatan tambahan..." @input="formDirty = true"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost" @click="closeModal">Batal</button>
          <button class="btn btn-primary" @click="submitForm">{{ editingTodo ? 'Perbarui' : 'Buat' }}</button>
        </div>
      </div>
    </div>

    <!-- TOAST -->
    <div class="toast-container">
      <div v-for="toast in toasts" :key="toast.id" class="toast" :class="'toast-' + toast.type">
        {{ toast.message }}
      </div>
    </div>

  </div>
</template>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');

:root {
  --bg-base: #06060f;
  --bg-surface: #0d0d1a;
  --bg-card: #111122;
  --bg-card-hover: #161630;
  --border: rgba(255,255,255,0.07);
  --purple: #8b5cf6;
  --indigo: #6366f1;
  --blue: #3b82f6;
  --cyan: #06b6d4;
  --green: #10b981;
  --amber: #f59e0b;
  --red: #ef4444;
  --orange: #f97316;
  --text-primary: #f1f0ff;
  --text-secondary: #9b93c4;
  --text-muted: #5a5480;
  --grad: linear-gradient(135deg, #8b5cf6, #6366f1, #3b82f6);
  --font: 'Plus Jakarta Sans', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { font-family: var(--font); background: var(--bg-base); color: var(--text-primary); min-height: 100vh; overflow-x: hidden; }

::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: var(--bg-surface); }
::-webkit-scrollbar-thumb { background: var(--purple); border-radius: 2px; }

.app-wrapper { display: flex; min-height: 100vh; }
.main-content { margin-left: 240px; flex: 1; min-height: 100vh; display: flex; flex-direction: column; }
.page-content { padding: 28px; flex: 1; }

.sidebar { width: 240px; min-height: 100vh; background: var(--bg-surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; left: 0; top: 0; z-index: 100; }
.sidebar-logo { padding: 24px 20px 20px; border-bottom: 1px solid var(--border); }
.logo-mark { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; background: var(--grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.logo-mark span { font-weight: 300; }
.logo-tagline { font-size: 10px; color: var(--text-muted); margin-top: 2px; letter-spacing: 1.5px; text-transform: uppercase; }
.sidebar-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 2px; }
.nav-section-label { font-size: 10px; color: var(--text-muted); letter-spacing: 1.5px; text-transform: uppercase; padding: 8px 8px 4px; margin-top: 8px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; cursor: pointer; font-size: 13.5px; font-weight: 500; color: var(--text-secondary); transition: all .2s ease; position: relative; }
.nav-item:hover { background: rgba(139,92,246,0.08); color: var(--text-primary); }
.nav-item.active { background: rgba(139,92,246,0.15); color: var(--purple); }
.nav-item.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 20px; background: var(--purple); border-radius: 0 3px 3px 0; }
.nav-icon { font-size: 16px; width: 20px; text-align: center; }
.nav-badge { margin-left: auto; background: var(--purple); color: white; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; }
.sidebar-footer { padding: 16px 12px; border-top: 1px solid var(--border); }
.user-profile { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 10px; cursor: pointer; }
.user-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--grad); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; color: white; }
.user-name { font-size: 13px; font-weight: 600; }
.user-role { font-size: 11px; color: var(--text-muted); }

.topbar { height: 60px; background: rgba(13,13,26,0.9); backdrop-filter: blur(20px); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; gap: 16px; position: sticky; top: 0; z-index: 50; }
.topbar-title { font-size: 15px; font-weight: 700; }
.topbar-subtitle { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.search-bar { margin-left: auto; display: flex; align-items: center; gap: 8px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 8px 14px; width: 240px; transition: all .2s; }
.search-bar:focus-within { border-color: var(--purple); box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
.search-bar input { background: none; border: none; outline: none; color: var(--text-primary); font-size: 13px; font-family: var(--font); width: 100%; }
.search-bar input::placeholder { color: var(--text-muted); }
.topbar-actions { display: flex; align-items: center; gap: 8px; }

/* FIX: style untuk loading indicator */
.loading-dot { font-size: 12px; color: var(--text-muted); animation: pulse 1.5s ease-in-out infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all .2s ease; font-family: var(--font); }
.btn-primary { background: var(--grad); color: white; box-shadow: 0 4px 20px rgba(139,92,246,0.25); }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(139,92,246,0.4); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.btn-ghost { background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--purple); color: var(--purple); }
.btn-danger { background: rgba(239,68,68,0.15); color: var(--red); border: 1px solid rgba(239,68,68,0.2); }
.btn-danger:hover { background: rgba(239,68,68,0.25); }
.btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }

.card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 22px; position: relative; overflow: hidden; transition: all .25s ease; }
.card:hover { border-color: rgba(139,92,246,0.2); }
.card-wide { grid-column: span 2; }
.card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }

.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 20px; position: relative; overflow: hidden; transition: all .25s; }
.stat-card:hover { transform: translateY(-2px); border-color: rgba(139,92,246,0.2); }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; }
.stat-card.c-purple::before { background: linear-gradient(90deg, transparent, var(--purple), transparent); }
.stat-card.c-blue::before { background: linear-gradient(90deg, transparent, var(--blue), transparent); }
.stat-card.c-green::before { background: linear-gradient(90deg, transparent, var(--green), transparent); }
.stat-card.c-red::before { background: linear-gradient(90deg, transparent, var(--red), transparent); }
.stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 14px; }
.stat-card.c-purple .stat-icon { background: rgba(139,92,246,0.15); color: var(--purple); }
.stat-card.c-blue .stat-icon { background: rgba(59,130,246,0.15); color: var(--blue); }
.stat-card.c-green .stat-icon { background: rgba(16,185,129,0.15); color: var(--green); }
.stat-card.c-red .stat-icon { background: rgba(239,68,68,0.15); color: var(--red); }
.stat-value { font-size: 30px; font-weight: 800; letter-spacing: -1px; }
.stat-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; font-weight: 500; }

.dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

.tab-group { display: flex; gap: 4px; background: var(--bg-surface); border-radius: 8px; padding: 3px; }
.tab-btn { padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; background: none; color: var(--text-muted); font-family: var(--font); transition: all .2s; }
.tab-btn.active { background: var(--bg-card); color: var(--purple); }

.bar-chart { display: flex; align-items: flex-end; gap: 8px; height: 160px; padding-top: 10px; }
.bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; }
.bar-fill { width: 100%; background: var(--grad); border-radius: 6px 6px 0 0; min-height: 4px; transition: height .5s ease; }
.bar-label { font-size: 10px; color: var(--text-muted); white-space: nowrap; }

.score-ring-wrap { position: relative; width: 120px; height: 120px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; }
.score-ring { width: 120px; height: 120px; }
.score-value { position: absolute; font-size: 24px; font-weight: 800; background: var(--grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.score-stats { display: flex; gap: 12px; justify-content: center; margin-top: 8px; }
.score-stat { text-align: center; }
.score-stat-val { font-size: 18px; font-weight: 800; }
.score-stat-label { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

.priority-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.priority-bar-wrap { flex: 1; height: 6px; background: rgba(255,255,255,0.05); border-radius: 3px; overflow: hidden; }
.priority-bar-fill { height: 100%; border-radius: 3px; transition: width .5s ease; }
.bar-critical { background: var(--red); }
.bar-high { background: var(--orange); }
.bar-medium { background: var(--amber); }
.bar-low { background: var(--cyan); }
.priority-count { font-size: 12px; color: var(--text-muted); font-weight: 600; min-width: 16px; text-align: right; }
.cat-label { font-size: 12px; color: var(--text-secondary); font-weight: 500; min-width: 80px; }

.badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; }
.badge-critical { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
.badge-high { background: rgba(249,115,22,0.15); color: #fb923c; border: 1px solid rgba(249,115,22,0.2); }
.badge-medium { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
.badge-low { background: rgba(6,182,212,0.15); color: #22d3ee; border: 1px solid rgba(6,182,212,0.2); }
.badge-cat { background: rgba(139,92,246,0.12); color: var(--purple); border: 1px solid rgba(139,92,246,0.2); }
.badge-status { background: rgba(99,102,241,0.12); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.2); }

.task-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); }
.task-row:last-child { border-bottom: none; }
.task-row-left { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.task-row-left input[type=checkbox] { accent-color: var(--purple); width: 15px; height: 15px; flex-shrink: 0; cursor: pointer; }
.done-text { text-decoration: line-through; color: var(--text-muted) !important; }

.task-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; margin-bottom: 10px; transition: all .25s ease; }
.task-card:hover { border-color: rgba(139,92,246,0.25); transform: translateX(2px); background: var(--bg-card-hover); }
.task-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.task-card-left { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.task-card-left input[type=checkbox] { accent-color: var(--purple); width: 16px; height: 16px; flex-shrink: 0; cursor: pointer; }
.task-card-actions { display: flex; gap: 6px; flex-shrink: 0; }
.task-title { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.task-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
.task-deadline { font-size: 11px; color: var(--text-muted); }

.progress-bar-wrap { height: 3px; background: rgba(255,255,255,0.06); border-radius: 2px; overflow: hidden; }
.progress-bar-fill { height: 100%; border-radius: 2px; background: var(--grad); transition: width .5s ease; }

.filter-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }

.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.section-title { font-size: 15px; font-weight: 700; }
.section-subtitle { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

.kanban-board { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.kanban-col { display: flex; flex-direction: column; }
.kanban-col-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-radius: 12px 12px 0 0; background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-bottom: none; font-size: 13px; font-weight: 700; }
.kanban-body { flex: 1; background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: 0 0 12px 12px; padding: 12px; display: flex; flex-direction: column; gap: 10px; min-height: 300px; }
.kanban-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; transition: all .2s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
.kanban-card:hover { border-color: rgba(139,92,246,0.3); transform: translateY(-2px); }
.kanban-card-title { font-size: 13.5px; font-weight: 600; margin-bottom: 8px; }
.kanban-card-actions { display: flex; gap: 6px; margin-top: 10px; justify-content: flex-end; }
.kanban-empty { text-align: center; color: var(--text-muted); font-size: 12px; padding: 20px 0; }

.focus-layout { display: grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start; }
.focus-main { display: flex; flex-direction: column; align-items: center; padding: 40px; text-align: center; }
.focus-mode-label { font-size: 12px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; padding: 6px 18px; border-radius: 20px; margin-bottom: 32px; }
.label-focus { background: rgba(139,92,246,0.15); color: var(--purple); border: 1px solid rgba(139,92,246,0.3); }
.label-break { background: rgba(16,185,129,0.15); color: var(--green); border: 1px solid rgba(16,185,129,0.3); }
.focus-ring-wrap { position: relative; width: 200px; height: 200px; display: flex; align-items: center; justify-content: center; margin-bottom: 32px; }
.focus-ring-svg { position: absolute; top: 0; left: 0; width: 200px; height: 200px; }
.focus-timer { position: relative; z-index: 1; font-size: 52px; font-weight: 800; letter-spacing: -3px; background: var(--grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-variant-numeric: tabular-nums; }
.focus-btns { display: flex; gap: 12px; margin-bottom: 20px; }
.focus-start-btn { min-width: 120px; justify-content: center; }
.focus-sessions { font-size: 13px; color: var(--text-muted); }
.focus-settings { display: flex; flex-direction: column; }
.focus-warning { font-size: 12px; color: var(--amber); margin-top: 8px; text-align: center; }
.range-labels { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted); margin-top: 4px; }
input[type=range] { width: 100%; accent-color: var(--purple); margin-top: 8px; cursor: pointer; }

.form-group { margin-bottom: 16px; }
.form-group label { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; display: block; letter-spacing: 0.3px; text-transform: uppercase; }
.form-group input, .form-group select, .form-group textarea { width: 100%; background: var(--bg-surface); border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; color: var(--text-primary); font-family: var(--font); font-size: 13.5px; outline: none; transition: all .2s; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--purple); box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
.form-group input::placeholder, .form-group textarea::placeholder { color: var(--text-muted); }
.form-group textarea { resize: vertical; min-height: 80px; }
.form-group select { appearance: none; cursor: pointer; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: var(--bg-card); border: 1px solid var(--border); border-radius: 20px; padding: 28px; width: 520px; max-width: 90vw; max-height: 85vh; overflow-y: auto; box-shadow: 0 40px 80px rgba(0,0,0,0.5); animation: modalIn .25s ease; }
@keyframes modalIn { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.modal-title { font-size: 18px; font-weight: 800; }
.modal-close { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border); background: none; color: var(--text-secondary); cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all .2s; }
.modal-close:hover { border-color: var(--red); color: var(--red); }
.modal-body { margin-bottom: 8px; }
.modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); }

.toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
.toast { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 14px 18px; min-width: 260px; max-width: 340px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); animation: toastIn .3s ease; font-size: 13px; font-weight: 500; }
@keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.toast-success { border-left: 3px solid var(--green); }
.toast-error { border-left: 3px solid var(--red); }
.toast-warning { border-left: 3px solid var(--amber); }
.toast-info { border-left: 3px solid var(--purple); }

.empty-state { text-align: center; padding: 48px 20px; color: var(--text-muted); font-size: 13px; }

@media (max-width: 1100px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .dashboard-grid { grid-template-columns: 1fr; }
  .card-wide { grid-column: span 1; }
  .kanban-board { grid-template-columns: 1fr; }
  .focus-layout { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); }
  .main-content { margin-left: 0; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .form-row { grid-template-columns: 1fr; }
}
</style>