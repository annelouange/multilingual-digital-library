import { Bookmark, Headphones, Heart, Star, Trash2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { assetUrl } from '../api/books.js';
import Button from './Button.jsx';

export default function BookCard({ book, role, onBorrow, onFavorite, onBookmark, onDelete }) {
  const coverUrl = assetUrl(book.coverImage);
  const initials = (book.title || 'IN').slice(0, 2).toUpperCase();
  return (
    <article className="book-card">
      <div className="book-cover" style={{ background: book.coverColor }}>
        {coverUrl ? <img src={coverUrl} alt={`${book.title} cover`} /> : <span>{initials}</span>}
      </div>
      <div className="book-body">
        <div>
          <p className="book-category">{book.category}</p>
          <h3>{book.title}</h3>
          <p>{book.author}</p>
        </div>
        <div className="book-meta">
          <span>{book.availableCopies} available</span>
          <span><Star size={14} /> {book.rating}</span>
        </div>
        <div className="book-actions">
          <Link className="button button-secondary button-sm" to={`/books/${book.id}`}>View</Link>
          <Link className="button button-ghost button-sm" to={`/reader/${book.id}`}><Headphones size={15} /> Listen</Link>
          {role === 'librarian_admin' && onDelete && <Button size="sm" variant="danger" onClick={() => onDelete(book)}><Trash2 size={15} /> Delete</Button>}
          {role === 'student' && onBorrow && <Button size="sm" onClick={() => onBorrow(book.id)}>Request borrow</Button>}
          {role === 'student' && onFavorite && <Button size="icon" variant="ghost" aria-label={`Favorite ${book.title}`} onClick={() => onFavorite(book.id)}><Heart size={16} /></Button>}
          {role !== 'librarian_admin' && onBookmark && <Button size="icon" variant="ghost" aria-label={`Bookmark ${book.title}`} onClick={() => onBookmark(book.id)}><Bookmark size={16} /></Button>}
        </div>
      </div>
    </article>
  );
}
