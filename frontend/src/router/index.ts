import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import type { Role } from '../types'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    guestOnly?: boolean
    roles?: Role[]
    bare?: boolean
  }
}

const STAFF: Role[] = ['institute_admin', 'teacher']
const ADMIN: Role[] = ['institute_admin']
const TENANT: Role[] = ['institute_admin', 'teacher', 'student', 'parent']
const LEARNERS: Role[] = ['student', 'parent']
const FEES: Role[] = ['institute_admin', 'student', 'parent']
const FORUM: Role[] = ['institute_admin', 'teacher', 'student']

export const routes: RouteRecordRaw[] = [
  { path: '/login', component: () => import('../views/LoginView.vue'), meta: { public: true, guestOnly: true, bare: true } },
  { path: '/register', component: () => import('../views/RegisterView.vue'), meta: { public: true, guestOnly: true, bare: true } },
  { path: '/verify/:serial?', component: () => import('../views/VerifyCertificateView.vue'), meta: { public: true, bare: true } },

  { path: '/', component: () => import('../views/DashboardView.vue') },
  { path: '/profile', component: () => import('../views/ProfileView.vue') },
  { path: '/institutes', component: () => import('../views/InstitutesView.vue'), meta: { roles: ['super_admin'] } },

  { path: '/students', component: () => import('../views/StudentsView.vue'), meta: { roles: STAFF } },
  { path: '/students/:id', component: () => import('../views/StudentDetailView.vue'), meta: { roles: [...STAFF, 'parent'] } },
  { path: '/teachers', component: () => import('../views/TeachersView.vue'), meta: { roles: ADMIN } },
  { path: '/parents', component: () => import('../views/ParentsView.vue'), meta: { roles: ADMIN } },
  { path: '/subjects', component: () => import('../views/SubjectsView.vue'), meta: { roles: ADMIN } },
  { path: '/batches', component: () => import('../views/BatchesView.vue'), meta: { roles: TENANT } },
  { path: '/batches/:id', component: () => import('../views/BatchDetailView.vue'), meta: { roles: STAFF } },
  { path: '/timetable', component: () => import('../views/TimetableView.vue'), meta: { roles: TENANT } },

  { path: '/sessions', component: () => import('../views/SessionsView.vue'), meta: { roles: STAFF } },
  { path: '/sessions/:id/attendance', component: () => import('../views/AttendanceView.vue'), meta: { roles: STAFF } },
  { path: '/my-attendance', component: () => import('../views/MyAttendanceView.vue'), meta: { roles: LEARNERS } },
  { path: '/fees', component: () => import('../views/FeesView.vue'), meta: { roles: FEES } },
  { path: '/receipts/:id', component: () => import('../views/ReceiptView.vue'), meta: { roles: FEES, bare: true } },

  { path: '/live', component: () => import('../views/LiveClassesView.vue'), meta: { roles: TENANT } },
  { path: '/lessons', component: () => import('../views/LessonsView.vue'), meta: { roles: TENANT } },
  { path: '/exams', component: () => import('../views/ExamsView.vue'), meta: { roles: TENANT } },
  { path: '/exams/:id/builder', component: () => import('../views/ExamBuilderView.vue'), meta: { roles: STAFF } },
  { path: '/exams/:id/results', component: () => import('../views/ExamResultsView.vue'), meta: { roles: STAFF } },
  { path: '/exams/:id/take', component: () => import('../views/ExamTakeView.vue'), meta: { roles: ['student'] } },
  { path: '/attempts/:id', component: () => import('../views/AttemptView.vue'), meta: { roles: TENANT } },
  { path: '/discussions', component: () => import('../views/DiscussionsView.vue'), meta: { roles: FORUM } },
  { path: '/discussions/:id', component: () => import('../views/ThreadView.vue'), meta: { roles: FORUM } },
  { path: '/leaderboard', component: () => import('../views/LeaderboardView.vue'), meta: { roles: TENANT } },
  { path: '/certificates', component: () => import('../views/CertificatesView.vue'), meta: { roles: TENANT } },
  { path: '/certificates/:id/print', component: () => import('../views/CertificatePrintView.vue'), meta: { roles: TENANT, bare: true } },

  { path: '/announcements', component: () => import('../views/AnnouncementsView.vue'), meta: { roles: TENANT } },
  { path: '/notifications', component: () => import('../views/NotificationsView.vue'), meta: { roles: TENANT } },
  { path: '/analytics', component: () => import('../views/AnalyticsView.vue'), meta: { roles: STAFF } },
  { path: '/audit-logs', component: () => import('../views/AuditLogView.vue'), meta: { roles: ADMIN } },

  { path: '/:pathMatch(.*)*', component: () => import('../views/NotFoundView.vue'), meta: { public: true } },
]

/** Pure guard logic, exported for unit testing. */
export function resolveAccess(
  meta: { public?: boolean; guestOnly?: boolean; roles?: Role[] },
  user: { role: Role } | null,
): true | string {
  if (meta.guestOnly && user) return '/'
  if (meta.public) return true
  if (!user) return '/login'
  if (meta.roles && !meta.roles.includes(user.role)) return '/'
  return true
}

const router = createRouter({ history: createWebHistory(), routes })

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  const result = resolveAccess(to.meta, auth.user)
  if (result === true) return true
  if (result === '/login') return { path: '/login', query: { redirect: to.fullPath } }
  return result === to.path ? true : result
})

export default router
