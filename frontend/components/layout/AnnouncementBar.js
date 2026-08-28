import Link from 'next/link';

/** Shown only when the admin switches it on in Site settings → Appearance. */
export default function AnnouncementBar({ settings }) {
  if (!settings?.announcement_enabled || !settings?.announcement_text) return null;

  const { announcement_text: text, announcement_link: link } = settings;

  return (
    <div className="announce">
      <div className="container d-flex align-items-center justify-content-center gap-2">
        <span className="announce__icon" aria-hidden="true">
          <i className="bi bi-truck" />
        </span>
        {link ? <Link href={link}>{text}</Link> : <span>{text}</span>}
      </div>
    </div>
  );
}
