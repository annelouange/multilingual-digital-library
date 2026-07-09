import { Navigate, Route, Routes } from 'react-router-dom';
import AppLayout from './layouts/AppLayout.jsx';
import ProtectedRoute from './routes/ProtectedRoute.jsx';
import { useAuth } from './context/AuthContext.jsx';
import { ROLES } from './utils/roles.js';
import Login from './pages/auth/Login.jsx';
import Register from './pages/auth/Register.jsx';
import ForgotPassword from './pages/auth/ForgotPassword.jsx';
import ResetPassword from './pages/auth/ResetPassword.jsx';
import Unauthorized from './pages/auth/Unauthorized.jsx';
import Profile from './pages/Profile.jsx';
import StudentDashboard from './pages/dashboards/StudentDashboard.jsx';
import LecturerDashboard from './pages/dashboards/LecturerDashboard.jsx';
import AdminDashboard from './pages/dashboards/AdminDashboard.jsx';
import SearchBooks from './pages/library/SearchBooks.jsx';
import BookDetails from './pages/library/BookDetails.jsx';
import Reader from './pages/library/Reader.jsx';
import VoiceSearch from './pages/library/VoiceSearch.jsx';
import PersonalLibrary from './pages/library/PersonalLibrary.jsx';
import PersonalReader from './pages/library/PersonalReader.jsx';
import BorrowedBooks from './pages/student/BorrowedBooks.jsx';
import ReadingProgress from './pages/student/ReadingProgress.jsx';
import Favorites from './pages/student/Favorites.jsx';
import Bookmarks from './pages/student/Bookmarks.jsx';
import UploadBook from './pages/student/UploadBook.jsx';
import BookSubmissions from './pages/student/BookSubmissions.jsx';
import Recommendations from './pages/shared/Recommendations.jsx';
import Notifications from './pages/shared/Notifications.jsx';
import ReadingLists from './pages/lecturer/ReadingLists.jsx';
import UploadBooks from './pages/lecturer/UploadBooks.jsx';
import UserManagement from './pages/admin/UserManagement.jsx';
import BookManagement from './pages/admin/BookManagement.jsx';
import BorrowingManagement from './pages/admin/BorrowingManagement.jsx';
import Analytics from './pages/admin/Analytics.jsx';
import Reports from './pages/admin/Reports.jsx';
import ActivityLogs from './pages/admin/ActivityLogs.jsx';
import Settings from './pages/admin/Settings.jsx';
import BookSubmissionReview from './pages/admin/BookSubmissionReview.jsx';
import CourseResources from './pages/lecturer/CourseResources.jsx';
import StudentEngagement from './pages/lecturer/StudentEngagement.jsx';
import AcademicManagement from './pages/admin/AcademicManagement.jsx';
import ReviewModeration from './pages/admin/ReviewModeration.jsx';
import RecommendationManagement from './pages/admin/RecommendationManagement.jsx';

function RootRedirect() {
  const { isAuthenticated, dashboardPath } = useAuth();
  return <Navigate to={isAuthenticated ? dashboardPath : '/login'} replace />;
}

function ProtectedShell() {
  return (
    <ProtectedRoute>
      <AppLayout />
    </ProtectedRoute>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<RootRedirect />} />
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/unauthorized" element={<Unauthorized />} />

      <Route element={<ProtectedShell />}>
        <Route path="/profile" element={<Profile />} />
        <Route path="/search" element={<SearchBooks />} />
        <Route path="/voice-search" element={<VoiceSearch />} />
        <Route path="/books/:id" element={<BookDetails />} />
        <Route path="/reader/:id" element={<Reader />} />
        <Route path="/my-library" element={<PersonalLibrary />} />
        <Route path="/my-library/:id" element={<PersonalReader />} />
        <Route path="/recommendations" element={<Recommendations />} />
        <Route path="/notifications" element={<Notifications />} />

        <Route path="/student/dashboard" element={<ProtectedRoute roles={[ROLES.STUDENT]}><StudentDashboard /></ProtectedRoute>} />
        <Route path="/student/borrowed" element={<ProtectedRoute roles={[ROLES.STUDENT]}><BorrowedBooks /></ProtectedRoute>} />
        <Route path="/student/progress" element={<ProtectedRoute roles={[ROLES.STUDENT]}><ReadingProgress /></ProtectedRoute>} />
        <Route path="/student/favorites" element={<ProtectedRoute roles={[ROLES.STUDENT]}><Favorites /></ProtectedRoute>} />
        <Route path="/student/bookmarks" element={<ProtectedRoute roles={[ROLES.STUDENT]}><Bookmarks /></ProtectedRoute>} />
        <Route path="/student/upload" element={<ProtectedRoute roles={[ROLES.STUDENT]}><UploadBook /></ProtectedRoute>} />
        <Route path="/student/submissions" element={<ProtectedRoute roles={[ROLES.STUDENT]}><BookSubmissions /></ProtectedRoute>} />

        <Route path="/lecturer/dashboard" element={<ProtectedRoute roles={[ROLES.LECTURER]}><LecturerDashboard /></ProtectedRoute>} />
        <Route path="/lecturer/reading-lists" element={<ProtectedRoute roles={[ROLES.LECTURER]}><ReadingLists /></ProtectedRoute>} />
        <Route path="/lecturer/uploads" element={<ProtectedRoute roles={[ROLES.LECTURER, ROLES.LIBRARIAN_ADMIN]}><UploadBooks /></ProtectedRoute>} />
        <Route path="/admin/uploads" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><UploadBooks /></ProtectedRoute>} />
        <Route path="/lecturer/resources" element={<ProtectedRoute roles={[ROLES.LECTURER]}><CourseResources /></ProtectedRoute>} />
        <Route path="/lecturer/engagement" element={<ProtectedRoute roles={[ROLES.LECTURER]}><StudentEngagement /></ProtectedRoute>} />

        <Route path="/admin/dashboard" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><AdminDashboard /></ProtectedRoute>} />
        <Route path="/admin/users" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><UserManagement /></ProtectedRoute>} />
        <Route path="/admin/books" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><BookManagement /></ProtectedRoute>} />
        <Route path="/admin/book-submissions" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><BookSubmissionReview /></ProtectedRoute>} />
        <Route path="/admin/borrowing" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><BorrowingManagement /></ProtectedRoute>} />
        <Route path="/admin/faculties" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><AcademicManagement /></ProtectedRoute>} />
        <Route path="/admin/reviews" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><ReviewModeration /></ProtectedRoute>} />
        <Route path="/admin/recommendations" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><RecommendationManagement /></ProtectedRoute>} />
        <Route path="/admin/analytics" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><Analytics /></ProtectedRoute>} />
        <Route path="/admin/reports" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><Reports /></ProtectedRoute>} />
        <Route path="/admin/logs" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><ActivityLogs /></ProtectedRoute>} />
        <Route path="/admin/settings" element={<ProtectedRoute roles={[ROLES.LIBRARIAN_ADMIN]}><Settings /></ProtectedRoute>} />
      </Route>

      <Route path="*" element={<RootRedirect />} />
    </Routes>
  );
}
