import { BookOpen, Clock, FileCheck2, Heart, ListChecks, Upload } from 'lucide-react';
import { Link } from 'react-router-dom';
import * as borrowApi from '../../api/borrow.js';
import * as favoritesApi from '../../api/favorites.js';
import * as progressApi from '../../api/progress.js';
import * as recommendationsApi from '../../api/recommendations.js';
import BookCard from '../../components/BookCard.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import DashboardBrand from '../../components/DashboardBrand.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatCard from '../../components/StatCard.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function StudentDashboard() {
  const borrowed = useAsync(borrowApi.myBorrowedBooks, []);
  const favorites = useAsync(favoritesApi.listFavorites, []);
  const readingProgress = useAsync(progressApi.listProgress, []);
  const recommended = useAsync(recommendationsApi.listRecommendations, []);
  const borrowedRows = borrowed.data || [];
  const now = Date.now();
  const dueSoon = borrowedRows.filter((row) => {
    const due = row.dueDate ? new Date(row.dueDate).getTime() : 0;
    return row.status === 'approved' && due >= now && due <= now + (7 * 24 * 60 * 60 * 1000);
  }).length;
  const progressRows = readingProgress.data || [];
  const averageProgress = progressRows.length
    ? Math.round(progressRows.reduce((sum, row) => sum + Number(row.progress || 0), 0) / progressRows.length)
    : 0;

  return (
    <>
      <PageHeader eyebrow="Student workspace" title="Dashboard" description="Continue reading, track requests, and discover course resources." />
      <DashboardBrand title="Welcome to MULTILINGUAL DIGITAL LIBRARY" description="Your academic reading, listening, borrowing, and upload workspace." />
      <div className="stat-grid">
        <StatCard label="Borrowed books" value={borrowedRows.length} icon={BookOpen} tone="blue" />
        <StatCard label="Due soon" value={dueSoon} icon={Clock} tone="amber" />
        <StatCard label="Reading progress" value={`${averageProgress}%`} icon={ListChecks} tone="green" />
        <StatCard label="Favorites" value={favorites.data?.length || 0} icon={Heart} tone="rose" />
      </div>
      <Card
        title="Add a book"
        eyebrow="Choose how to publish"
        actions={(
          <div className="button-row">
            <Link className="button button-primary button-md" to="/student/upload"><Upload size={16} /> Upload directly</Link>
            <Link className="button button-secondary button-md" to="/student/submissions"><FileCheck2 size={16} /> Submit for approval</Link>
          </div>
        )}
      >
        <p>Direct uploads join the searchable catalog immediately. Submitted books remain private until a librarian verifies and approves them.</p>
      </Card>
      <DataState loading={recommended.loading} error={recommended.error} empty={!recommended.data?.length} onRetry={recommended.reload}>
        <Card title="Recommended for you" eyebrow="Based on your course">
          <div className="book-grid">{recommended.data?.map((book) => <BookCard key={book.id} book={book} role="student" />)}</div>
        </Card>
      </DataState>
    </>
  );
}
