export const ROLES = {
  STUDENT: 'student',
  LECTURER: 'lecturer',
  LIBRARIAN_ADMIN: 'librarian_admin',
};

export const roleLabels = {
  [ROLES.STUDENT]: 'Student',
  [ROLES.LECTURER]: 'Lecturer',
  [ROLES.LIBRARIAN_ADMIN]: 'Librarian/Admin',
};

export function getDashboardPath(role) {
  if (role === ROLES.LECTURER) return '/lecturer/dashboard';
  if (role === ROLES.LIBRARIAN_ADMIN) return '/admin/dashboard';
  return '/student/dashboard';
}
