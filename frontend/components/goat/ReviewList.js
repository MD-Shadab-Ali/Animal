import { formatDate } from '@/lib/format';

export default function ReviewList({ reviews = [], rating }) {
  if (!reviews.length) {
    return (
      <p className="text-soft mb-0">
        No reviews yet. Customers can review a goat once it has been delivered.
      </p>
    );
  }

  return (
    <div>
      {rating?.count > 0 && (
        <div className="d-flex align-items-center gap-2 mb-4">
          <span className="h4 mb-0 fw-bold">{rating.average}</span>
          <span className="quote__stars">
            {'★'.repeat(Math.round(rating.average))}{'☆'.repeat(Math.max(0, 5 - Math.round(rating.average)))}
          </span>
          <span className="text-soft small">({rating.count} review{rating.count === 1 ? '' : 's'})</span>
        </div>
      )}

      <div className="d-grid gap-3">
        {reviews.map((review) => (
          <div className="panel" key={review.id}>
            <div className="d-flex justify-content-between align-items-start gap-2 mb-2">
              <div>
                <strong>{review.author || 'Verified buyer'}</strong>
                <div className="quote__stars small">
                  {'★'.repeat(review.rating)}{'☆'.repeat(5 - review.rating)}
                </div>
              </div>
              <span className="small text-soft">{formatDate(review.created_at)}</span>
            </div>

            {review.title && <h4 className="h6 mb-1">{review.title}</h4>}
            {review.comment && <p className="mb-0 small">{review.comment}</p>}
          </div>
        ))}
      </div>
    </div>
  );
}
