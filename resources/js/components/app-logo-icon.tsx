import { SVGAttributes } from 'react';

/** Biểu tượng Video Aff: khung video dọc 9:16 với nút phát và tia sáng (AI). */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M12 4h12a5 5 0 0 1 5 5v22a5 5 0 0 1-5 5H12a5 5 0 0 1-5-5V9a5 5 0 0 1 5-5Zm0 3a2 2 0 0 0-2 2v22a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2H12Zm3 7.2v11.6a1 1 0 0 0 1.52.85l9.3-5.8a1 1 0 0 0 0-1.7l-9.3-5.8a1 1 0 0 0-1.52.85ZM33 2l1.2 3.3L37.5 6.5l-3.3 1.2L33 11l-1.2-3.3L28.5 6.5l3.3-1.2L33 2Z"
            />
        </svg>
    );
}
