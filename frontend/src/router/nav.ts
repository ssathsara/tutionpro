import type { Role } from '../types'

export interface NavItem { label: string; to: string; icon: string; roles: Role[]; group?: string }

const STAFF: Role[] = ['institute_admin', 'teacher']
const LEARNERS: Role[] = ['student', 'parent']
const ALL_TENANT: Role[] = ['institute_admin', 'teacher', 'student', 'parent']

export const NAV: NavItem[] = [
  { label: 'Dashboard', to: '/', icon: 'home', roles: [...ALL_TENANT, 'super_admin'] },
  { label: 'Institutes', to: '/institutes', icon: 'building', roles: ['super_admin'], group: 'Platform' },

  { label: 'Students', to: '/students', icon: 'users', roles: STAFF, group: 'Manage' },
  { label: 'Teachers', to: '/teachers', icon: 'user', roles: ['institute_admin'], group: 'Manage' },
  { label: 'Parents', to: '/parents', icon: 'users', roles: ['institute_admin'], group: 'Manage' },
  { label: 'Subjects', to: '/subjects', icon: 'book', roles: ['institute_admin'], group: 'Manage' },
  { label: 'Batches', to: '/batches', icon: 'layers', roles: ALL_TENANT, group: 'Manage' },
  { label: 'Timetable', to: '/timetable', icon: 'calendar', roles: ALL_TENANT, group: 'Manage' },

  { label: 'Classes & Attendance', to: '/sessions', icon: 'check', roles: STAFF, group: 'Operations' },
  { label: 'Attendance', to: '/my-attendance', icon: 'check', roles: LEARNERS, group: 'Operations' },
  { label: 'Fees', to: '/fees', icon: 'cash', roles: ['institute_admin', 'student', 'parent'], group: 'Operations' },

  { label: 'Live Classes', to: '/live', icon: 'video', roles: ALL_TENANT, group: 'Learning' },
  { label: 'Lessons', to: '/lessons', icon: 'play', roles: ALL_TENANT, group: 'Learning' },
  { label: 'Exams', to: '/exams', icon: 'edit', roles: ALL_TENANT, group: 'Learning' },
  { label: 'Discussions', to: '/discussions', icon: 'chat', roles: ['institute_admin', 'teacher', 'student'], group: 'Learning' },
  { label: 'Leaderboard', to: '/leaderboard', icon: 'trophy', roles: ALL_TENANT, group: 'Learning' },
  { label: 'Certificates', to: '/certificates', icon: 'award', roles: ALL_TENANT, group: 'Learning' },

  { label: 'Announcements', to: '/announcements', icon: 'megaphone', roles: ALL_TENANT, group: 'Communication' },
  { label: 'Notifications', to: '/notifications', icon: 'bell', roles: ALL_TENANT, group: 'Communication' },

  { label: 'Analytics', to: '/analytics', icon: 'chart', roles: STAFF, group: 'Insights' },
  { label: 'Audit Log', to: '/audit-logs', icon: 'shield', roles: ['institute_admin'], group: 'Insights' },
]

export function navFor(role: Role | null): NavItem[] {
  if (!role) return []
  return NAV.filter((n) => n.roles.includes(role))
}
